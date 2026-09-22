<?php

declare(strict_types=1);

namespace CcGpt;

use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Preset;
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\Plugin\OpenAI\Client;

/**
 * REPL 主程序(design/21 §8):读输入 → 命令 or Agent->run() 渲染事件流。
 *
 * 装配(design/21 §8.1):Preset → 工具注册进 Agent + ModelSource 注入 /model;
 * 配置三层:env(CC_GPT_API_KEY)> cc-gpt/.settings.json > 提示 /init。
 */
final class Application
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /** @var CommandRegistry */
    private $commands;

    /** @var Output */
    private $output;

    /** @var Input */
    private $input;

    /** @var Context */
    private $context;

    /**
     * @param string $repoRoot 壳项目根(examples/cc-gpt;bin 传 __DIR__.'/..',与调用者 cwd 无关)
     * @param string|null $workOverride 沙箱 root 覆盖(--work;null = <repoRoot>/.work)
     */
    public function __construct(string $repoRoot, ?Output $output = null, ?Input $input = null, ?string $workOverride = null)
    {
        $this->output = $output ?? new Output();
        $this->input = $input ?? new Input();

        // 三区(design/21 §8):.settings.json 壳根 / .work 沙箱 primary / .runtime 草稿区(目录常驻,运行时自动创建)
        $this->repoRoot = $repoRoot;
        $sandbox = new Sandbox(
            $workOverride ?? $repoRoot . '/.work',
            [$repoRoot . '/.runtime']
        );
        $settings = new Settings($repoRoot);

        $this->context = new Context($this->output, $this->input, $settings);
        $this->context->runtimeDir = $repoRoot . '/.runtime';
        $this->commands = new CommandRegistry();
        foreach ($this->defaultCommands() as $command) {
            $this->commands->register($command);
        }
        $this->context->commands = $this->commands; // @phpstan-ignore-line (Help 消费)

        $this->bootstrapAgent($sandbox);
    }

    /** @var string 壳项目根(tools/ 装载等使用) */
    private $repoRoot;

    /**
     * 内建命令集(壳恒注册;使用者可在 registry 上追加)。
     *
     * @return CommandInterface[]
     */
    private function defaultCommands(): array
    {
        return [
            new Command\Init(),
            new Command\Model(),
            new Command\ShowContext(),
            new Command\Help(),
            new Command\Quit(),
        ];
    }

    public function commands(): CommandRegistry
    {
        return $this->commands;
    }

    public function context(): Context
    {
        return $this->context;
    }

    /**
     * Agent 装配:key 三层(env > settings);Preset 工具注入。
     */
    public function bootstrapAgent(Sandbox $sandbox, ?Preset $preset = null): void
    {
        $apiKey = \getenv('CC_GPT_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            $apiKey = $this->context->settings->get('apiKey');
        }
        if (!\is_string($apiKey) || $apiKey === '') {
            $this->output->writeln('no API key yet — run /init <key> [baseUrl]', 'yellow');

            return;
        }

        $baseUrl = \getenv('CC_GPT_BASE_URL');
        if ($baseUrl === false || $baseUrl === '') {
            $baseUrl = (string) $this->context->settings->get('baseUrl', self::DEFAULT_BASE_URL);
        }

        $client = new Client($apiKey, null, $baseUrl);
        $model = (string) $this->context->settings->get('model', '');
        $this->context->model = $model;
        $this->context->agent = new Agent($client, $model !== '' ? $model : 'gpt-4o-mini');

        $preset = $preset ?? $this->defaultPreset($sandbox, $baseUrl);
        if ($preset !== null) {
            foreach ($preset->tools() as $tool) {
                $this->context->agent->tools()->register($tool);
            }
            $this->context->modelSource = $preset->modelSource();
        }

        // 声明式工具(design/25 §4):tools/ 目录 *.tool.json 装载(结构性安全,默认装载;
        // 单文件非法被 Loader 跳过并留痕,启动时汇总提示)
        $loader = new \Ws\Http\NanoGpt\DescriptorToolLoader(
            $this->repoRoot . '/tools',
            new \Ws\Http\NanoGpt\CommandExecKernel($this->repoRoot . '/.runtime', 30, 2048)
        );
        foreach ($loader->loadAll() as $tool) {
            $this->context->agent->tools()->register($tool);
        }
    }

    /**
     * 出厂默认预设(design/21 §8 + design/25 §4.5 评审决策):
     * - 文件工具(含 delete_file,Sandbox 绝对边界)+ ApiGetTool(白名单空 = 禁用);
     * - ExecTool 显式开启(claude code 同款只读集,php/node 跑 .runtime 脚本);
     * - C5b 网络工具:验证(api_test)与提取(api_fetch)默认随 GET 白名单开启;
     *   api_json_post 同白名单(body 走文件形态,结构性安全)。
     */
    private function defaultPreset(Sandbox $sandbox, string $baseUrl): ?Preset
    {
        $preset = (new \Ws\Http\NanoGpt\FullPreset($sandbox, null, []))
            ->withExec(\Ws\Http\NanoGpt\FullPreset::defaultExecBinaries(), $this->context->runtimeDir);

        // C5b:GET 白名单非空时追加验证/提取/POST 三工具(与 http_get 同一白名单授权面)
        $hosts = $this->context->settings->get('httpAllowHosts');
        if (\is_array($hosts) && $hosts !== []) {
            $preset = $preset->withHttpTools(array_values($hosts));
        }

        return $preset;
    }

    /**
     * REPL 主循环。返回退出码。
     */
    public function run(): int
    {
        $this->output->writeln('cc-gpt — type /help for commands', 'bright_cyan');

        while (true) {
            $line = $this->input->readLine('you> ');
            if ($line === null) { // EOF
                $this->output->writeln();

                return 0;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed[0] === '/') {
                $parts = preg_split('/\s+/', substr($trimmed, 1)) ?: [];
                $name = (string) array_shift($parts);
                try {
                    if (!$this->commands->get($name)->execute($parts, $this->context)) {
                        return 0;
                    }
                } catch (\InvalidArgumentException $e) {
                    $this->output->writeln($e->getMessage() . ' — /help', 'red');
                }
                continue;
            }

            $this->runAgentTurn($trimmed);
        }
    }

    private function runAgentTurn(string $input): void
    {
        if ($this->context->agent === null) {
            $this->output->writeln('agent not configured — run /init <key> first', 'yellow');

            return;
        }

        try {
            foreach ($this->context->agent->run($input) as $event) {
                $this->renderEvent($event);
            }
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            $this->output->writeln('agent error [' . $e->getCode() . ']: ' . $e->getMessage(), 'red');
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function renderEvent(array $event): void
    {
        switch ($event['type']) {
            case 'text':
                $this->output->writeln($event['text']);
                break;
            case 'tool_call':
                $this->output->writeln(sprintf('⚙ %s %s', $event['name'], json_encode($event['args'], JSON_UNESCAPED_UNICODE)), 'cyan');
                break;
            case 'tool_result':
                $excerpt = mb_substr((string) $event['result'], 0, 200);
                $this->output->writeln('  ↳ ' . $excerpt, 'gray');
                break;
            case 'error':
                $this->output->writeln('! ' . $event['message'], 'red');
                break;
        }
    }
}
