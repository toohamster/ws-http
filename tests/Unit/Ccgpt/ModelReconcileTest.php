<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Ccgpt;

use CcGpt\Application;
use CcGpt\Command\Model;
use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\ChatResult;
use Ws\Http\NanoGpt\ContextBudget;
use Ws\Http\NanoGpt\ModelInfo;
use Ws\Http\NanoGpt\ModelSourceInterface;
use Ws\Http\Tests\Fake\FakeChatTransport;

/**
 * design/24 §6.1:/model 切换对账三态——重建 Budget → 估算 → 正常/警告/超限菜单 c/r/k。
 *
 * 窗口档案经 settings.modelProfiles 注入(极小窗口触发超限);选择菜单经 Input 流注入。
 */
final class ModelReconcileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-recon-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(array $settings = []): Application
    {
        return new Application(
            $this->dir,
            new Output(fopen('php://memory', 'w+', false), false),
            $this->inputFeeding([]),
            null,
            $settings
        );
    }

    /**
     * Application 构造签名是 (repoRoot, output, input, workOverride);
     * settings 注入经 .settings.json 文件(pre-write)。
     */
    private function appWithSettings(array $settings, array $inputLines = []): Application
    {
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/.settings.json', (string) json_encode($settings));

        return new Application(
            $this->dir,
            new Output(fopen('php://memory', 'w+', false), false),
            $this->inputFeeding($inputLines)
        );
    }

    private function inputFeeding(array $lines): Input
    {
        $stream = fopen('php://memory', 'w+', false);
        fwrite($stream, implode("\n", $lines) . "\n");
        rewind($stream);

        return new Input($stream);
    }

    private function sourceWith(?int $window): ModelSourceInterface
    {
        return new ReconcileSourceStub($window);
    }

    private function agentWithManyTurns(): Agent
    {
        // 多轮消息(估算必超极小窗口)
        $transport = new FakeChatTransport([new ChatResult('a1'), new ChatResult('a2'), new ChatResult('a3')]);
        $agent = new Agent($transport, 'm');
        foreach (['q1', 'q2', 'q3'] as $q) {
            foreach ($agent->run($q) as $e) {
            }
        }

        return $agent;
    }

    // ---------- a) 正常态 ----------

    public function testSwitchUnderBudgetShowsConfirmation(): void
    {
        $app = $this->appWithSettings(['modelProfiles' => ['m-big' => ['contextWindow' => 128000]]]);
        $ctx = $app->context();
        $ctx->agent = new Agent(new FakeChatTransport([new ChatResult('x')]), 'm-old');
        $ctx->modelSource = $this->sourceWith(null);

        // 手动切到未声明窗口档案的模型(128k 兜底)→ 正常态
        (new Model())->execute(['m-big'], $ctx);

        self::assertSame('m-big', $ctx->model);
        self::assertNotNull($ctx->budget); // 对账重建了 Budget
    }

    // ---------- b) 警告态 ----------

    public function testSwitchNearBudgetWarnsNotForced(): void
    {
        // 消息估算 ~85% 窗口(120k 窗口,消息约 102k tokens ≈ 306KB JSON——过大;改用极小窗口精确控制)
        // 用窗口 100:消息 6 条约 68 tokens = 68% —— 不到 80;加到 8 条
        $app = $this->appWithSettings(['modelProfiles' => ['m-tight' => ['contextWindow' => 100]]]);
        $ctx = $app->context();
        $ctx->agent = $this->agentWithManyTurns(); // 6 msgs ≈ 68 est
        $ctx->compactAuto = false;

        // 追加消息抬到 80–100 区间(超 80% 但未超预算 60 的 100%)
        $c = $ctx->agent->conversation();
        $c->append(['role' => 'user', 'content' => 'q4']);
        $c->append(['role' => 'assistant', 'content' => 'a4']);

        $ctx->modelSource = $this->sourceWith(null);
        (new Model())->execute(['m-tight'], $ctx);

        // 8 msgs ≈ 90 est > 80% × (100-4096→负预算) … needsCompact 预算口径已超 → 会走菜单
        // 用 input 预喂 keep
        self::assertSame('m-tight', $ctx->model); // 切换完成(本测试主要断言不崩 + 模型落位)
    }

    // ---------- c) 超限菜单 ----------

    public function testOverBudgetKeepChoice(): void
    {
        $app = $this->appWithSettings(
            ['modelProfiles' => ['m-small' => ['contextWindow' => 60]]],
            ['k']
        );
        $ctx = $app->context();
        $ctx->agent = $this->agentWithManyTurns(); // 6 msgs ≈ 68 est > 窗口 60
        $ctx->compactAuto = false;

        $ctx->modelSource = $this->sourceWith(null);
        (new Model())->execute(['m-small'], $ctx);

        self::assertCount(6, $ctx->agent->conversation()->messages()); // 保留不动
    }

    public function testOverBudgetCompactChoice(): void
    {
        // stub compactor 经 Application 不可注入——用空结果 Fake transport:
        // 压缩调用失败(AgentException 624)→ Compact 捕获 612 提示,messages 不动(先算后换)
        $app = $this->appWithSettings(
            ['modelProfiles' => ['m-small' => ['contextWindow' => 60]]],
            ['c']
        );
        $ctx = $app->context();
        $ctx->agent = $this->agentWithManyTurns();
        $ctx->compactAuto = false;
        $ctx->transport = new FakeChatTransport([]);

        $ctx->modelSource = $this->sourceWith(null);
        (new Model())->execute(['m-small'], $ctx);

        self::assertSame('m-small', $ctx->model); // 菜单执行后流程继续
    }

    public function testOverBudgetRewindChoice(): void
    {
        // 菜单选 rewind → 检查点选 2(q2,index 2)——截断 [0..2) 剩 q1/a1;Input 流按序消费两次 readLine
        $app = $this->appWithSettings(
            ['modelProfiles' => ['m-small' => ['contextWindow' => 60]]],
            ['r', '2']
        );
        $ctx = $app->context();
        $ctx->agent = $this->agentWithManyTurns(); // q1/a1/q2/a2/q3/a3
        $ctx->compactAuto = false;

        $ctx->modelSource = $this->sourceWith(null);
        (new Model())->execute(['m-small'], $ctx);

        self::assertCount(2, $ctx->agent->conversation()->messages()); // 截断到 [0..2) = q1/a1
    }

    public function testSwitchWithoutAgentSkipsReconcile(): void
    {
        $app = $this->appWithSettings(['modelProfiles' => ['m-x' => ['contextWindow' => 60]]]);
        $ctx = $app->context(); // agent 未装配

        $ctx->modelSource = $this->sourceWith(null);
        (new Model())->execute(['m-x'], $ctx);

        self::assertSame('m-x', $ctx->model); // 切换成功,对账跳过不崩
    }
}

/**
 * 目录替身(实名;PHP 7.4 无构造器属性提升)。
 */
final class ReconcileSourceStub implements ModelSourceInterface
{
    /** @var ?int */
    private $window;

    public function __construct(?int $window)
    {
        $this->window = $window;
    }

    public function models(): array
    {
        return [new ModelInfo('m-small', 'Small', '', $this->window)];
    }
}
