<?php

declare(strict_types=1);

namespace CcGpt;

use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Preset;
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Transport\ChatTransportInterface;
use Ws\Http\NanoGpt\Transport\GenericOpenAiTransport;
use Ws\Http\NanoGpt\Transport\OpenAiCompatibleTransport;
use Ws\Http\NanoGpt\Transport\OrcaRouterTransport;

/**
 * REPL 主程序(design/21 §8;design/28 D3 装配修订):读输入 → 命令 or Agent->run() 渲染事件流。
 *
 * 装配(design/28 §5):settings.adapter → Transport 厂商类(对话/压缩/探测共用同一实例);
 * ModelProvider 适配器薄壳化(目录解析归 Transport::capabilities);配置三层:
 * env(CC_GPT_API_KEY)> cc-gpt/.settings.json > 提示 /init。
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
        $this->context->currentSessionId = date('Ymd-His'); // session 身份归壳(design/24 §3)
        $this->context->sessionStore = new \Ws\Http\NanoGpt\FileConversationStore($repoRoot . '/.runtime/sessions');
        $this->context->memory = new \Ws\Http\NanoGpt\Memory($workOverride ?? $repoRoot . '/.work' . '/memory.json');
        $this->context->compactAuto = $settings->get('compactAuto') === null || (bool) $settings->get('compactAuto');
        $this->context->application = $this; // /skill 发起 agent 轮(design/26 §4)
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
     * Compact 的 compactor 延迟到 runAgentTurn(装配期 Transport 未必就绪;
     * 同一 Transport 实例,design/28 D3)。
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
            new Command\Compact(new \Ws\Http\NanoGpt\LlmCompactor(
                $this->context->transport ?? new GenericOpenAiTransport(['api_key' => 'unset']),
                'gpt-4o-mini'
            ), $this->context->sessionStore),
            new Command\Rewind(),
            new Command\Clear($this->context->sessionStore),
            new Command\Resume($this->context->sessionStore),
            new Command\Memory(),
            new Command\Skill(new SkillLoader($this->repoRoot . '/skills')),
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
     * Agent 装配(design/28 D3):settings.adapter → Transport 厂商类;
     * key/baseUrl 三层(env > settings);Preset 工具注入。
     */
    public function bootstrapAgent(Sandbox $sandbox, ?Preset $preset = null): void
    {
        $apiKey = \getenv('CC_GPT_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            $apiKey = $this->context->settings->get('apiKey');
        }
        if (!\is_string($apiKey) || $apiKey === '') {
            $this->output->writeln('no API key yet — run /init (adapter menu → key/url)', 'yellow');

            return;
        }

        $baseUrl = \getenv('CC_GPT_BASE_URL');
        if ($baseUrl === false || $baseUrl === '') {
            $baseUrl = (string) $this->context->settings->get('baseUrl', $this->defaultBaseUrl());
        }

        $transport = $this->buildTransport((string) $apiKey, $baseUrl);
        $this->context->transport = $transport; // 压缩/探测共用(与 agent 同凭据)
        $model = (string) $this->context->settings->get('model', '');
        $this->context->model = $model;
        $this->context->agent = new Agent($transport, $model !== '' ? $model : $this->defaultModel());

        // M4:窗口预算(模型能力档案 settings.modelProfiles[<model>] 优先,settings.contextWindow 兜底)
        $this->context->budget = $this->buildBudget($model !== '' ? $model : $this->defaultModel());

        $preset = $preset ?? $this->defaultPreset(
            $sandbox,
            $this->modelSource($transport)
        );
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
     * 服务身份 → Transport 厂商类(design/28 §5 维度 B:知识有家)。
     * settings.adapter 显式分派(/init 选择时写入);未选择/未知 id →
     * GenericOpenAiTransport(逃生门:baseUrl 经 config 注入)。
     */
    private function buildTransport(string $apiKey, string $baseUrl): ChatTransportInterface
    {
        $config = ['api_key' => $apiKey, 'base_url' => $baseUrl];

        $adapterId = $this->context->settings->get('adapter');
        if (\is_string($adapterId) && $adapterId !== '') {
            foreach (\CcGpt\Command\Init::adapterClasses() as $candidate) {
                if ($candidate::id() === $adapterId) {
                    return $this->transportForAdapter($candidate::id(), $config);
                }
            }
        }

        return new GenericOpenAiTransport($config);
    }

    /**
     * adapter id → Transport 厂商类映射(壳层装配表;新增厂商 = Transport 类 +
     * 此处一行 + ModelProvider 适配器类)。
     *
     * @param array{api_key: string, base_url: string} $config
     */
    private function transportForAdapter(string $adapterId, array $config): ChatTransportInterface
    {
        switch ($adapterId) {
            case 'orcarouter':
                return new OrcaRouterTransport($config);
            case 'generic':
            default: // generic 走 GenericOpenAiTransport(config 的 baseUrl 即服务端点)
                return new GenericOpenAiTransport($config);
        }
    }

    /**
     * settings.adapter 显式分派(design/21 §8.2 三次评审):/init 选择时写入,
     * 此处按 id 查表定位;无选择 → settings.models 手写档案或 null(手动 /model <id>)。
     * 无 host 字符串嗅探(已否决)。目录解析归 Transport(适配器薄壳委托 capabilities)。
     */
    private function modelSource(ChatTransportInterface $transport): ?\CcGpt\ModelProvider\ModelSourceInterface
    {
        $adapterId = $this->context->settings->get('adapter');
        if (\is_string($adapterId) && $adapterId !== '') {
            foreach (\CcGpt\Command\Init::adapterClasses() as $candidate) {
                if ($candidate::id() === $adapterId) {
                    return new $candidate($transport);
                }
            }
        }

        $models = $this->context->settings->get('models');
        if (\is_array($models) && $models !== []) {
            return new \CcGpt\ModelProvider\StaticAdapter(array_values($models));
        }

        return null; // 手动 /model <id>(design/21 §8.1 契约)
    }

    /**
     * 出厂默认预设(design/21 §8 + design/25 §4.5 评审决策):
     * - 文件工具(含 delete_file,Sandbox 绝对边界)+ ApiGetTool(白名单空 = 禁用);
     * - ExecTool 显式开启(claude code 同款只读集,php/node 跑 .runtime 脚本);
     * - C5b 网络工具:验证(api_test)与提取(api_fetch)默认随 GET 白名单开启;
     *   api_json_post 同白名单(body 走文件形态,结构性安全)。
     */
    private function defaultPreset(Sandbox $sandbox, ?\CcGpt\ModelProvider\ModelSourceInterface $modelSource): ?Preset
    {
        $preset = (new \Ws\Http\NanoGpt\FullPreset($sandbox, $modelSource, []))
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

    /**
     * 窗口预算构建(M4):窗口 = settings.modelProfiles[<model>].contextWindow
     * (§8.2 级联落点)→ settings.contextWindow 兜底 → 默认 128000。
     */
    private function buildBudget(string $model): \Ws\Http\NanoGpt\ContextBudget
    {
        $profiles = $this->context->settings->get('modelProfiles');
        $window = \is_array($profiles) && isset($profiles[$model]['contextWindow']) && \is_int($profiles[$model]['contextWindow'])
            ? $profiles[$model]['contextWindow']
            : (int) $this->context->settings->get('contextWindow', 128000);

        return new \Ws\Http\NanoGpt\ContextBudget($window);
    }

    public function runAgentTurn(string $input): void
    {
        if ($this->context->agent === null) {
            $this->output->writeln('agent not configured — run /init <key> first', 'yellow');

            return;
        }

        // 自动压缩(design/24 §7):每轮 run 前 needsCompact → 保留 2 轮;compactAuto=false 关闭
        if ($this->context->transport !== null) {
            \CcGpt\Command\Compact::maybeAuto($this->context, new \Ws\Http\NanoGpt\LlmCompactor(
                $this->context->transport,
                $this->context->model !== '' ? $this->context->model : $this->defaultModel()
            ));
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
     * 厂商默认模型兜底(装配层;settings.model 未选时 Transport 厂商常量为主)。
     */
    private function defaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    private function defaultBaseUrl(): string
    {
        return self::DEFAULT_BASE_URL;
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
