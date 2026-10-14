<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Ccgpt;

use CcGpt\Command\Clear;
use CcGpt\Command\Compact;
use CcGpt\Command\Memory as MemoryCommand;
use CcGpt\Command\Resume;
use CcGpt\Command\Rewind;
use CcGpt\Context;
use CcGpt\Settings;
use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\CompactorInterface;
use Ws\Http\NanoGpt\Conversation;
use Ws\Http\NanoGpt\FileConversationStore;
use Ws\Http\NanoGpt\Memory;
use Ws\Http\NanoGpt\ModelInfo;
use Ws\Http\NanoGpt\ModelSourceInterface;
use Ws\Http\Tests\Fake\FakeChatTransport;

/**
 * design/24 §7(M4):壳命令族——/compact /rewind /clear /resume /memory + 自动压缩服务。
 *
 * Agent 以真实实例 + 空 tools 构成(不经网络:命令族只操作 Conversation,不触发 run);
 * 压缩用 stub Compactor(固定摘要,不依赖 LLM)。
 */
final class ShellCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-m4-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function context(array $settingsData = [], ?Input $input = null): Context
    {
        $ctx = new Context(new Output(fopen('php://memory', 'w+', false), false), $input ?? new Input(), new Settings($this->dir, $settingsData));
        $ctx->runtimeDir = $this->dir . '/.runtime';

        return $ctx;
    }

    private function agentWithConversation(): Agent
    {
        return new Agent(new FakeChatTransport([new \Ws\Http\NanoGpt\ChatResult('')]), 'm');
    }

    private function stubCompactor(): CompactorInterface
    {
        return new class implements CompactorInterface {
            public function compact(array $messages): array
            {
                return ['role' => 'user', 'content' => '【历史摘要】stub summary of ' . count($messages) . ' messages'];
            }
        };
    }

    private function multiTurnConversation(Agent $agent): Conversation
    {
        $c = $agent->conversation();
        $c->append(['role' => 'user', 'content' => 'q1']);
        $c->append(['role' => 'assistant', 'content' => 'a1']);
        $c->append(['role' => 'user', 'content' => 'q2']);
        $c->append(['role' => 'assistant', 'content' => 'a2']);
        $c->append(['role' => 'user', 'content' => 'q3']);
        $c->append(['role' => 'assistant', 'content' => 'a3']);
        $c->accumulateUsage((object) ['prompt_tokens' => 30, 'completion_tokens' => 10, 'total_tokens' => 40]);

        return $c;
    }

    // ---------- /compact ----------

    public function testCompactKeepsLastTwoTurnsAndSummarizesEarlier(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $this->multiTurnConversation($ctx->agent);

        $cmd = new Compact($this->stubCompactor());
        $cmd->execute([], $ctx); // keep 默认 2 轮 = 4 条消息(q3/a3 + q2/a2)

        $msgs = $c->messages();
        self::assertSame('user', $msgs[0]['role']);
        self::assertStringStartsWith('【历史摘要】', (string) $msgs[0]['content']); // 更早 6 条 → 1 条摘要
        self::assertCount(5, $msgs); // 摘要 + q2/a2/q3/a3
        self::assertSame('a3', $msgs[4]['content']);
        // usage 不重置
        self::assertSame(40, $c->usage()['total_tokens']);
    }

    public function testCompactKeepParamAndSavesSession(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $this->multiTurnConversation($ctx->agent);
        $store = new FileConversationStore($this->dir . '/sessions');
        $ctx->currentSessionId = '20261008-090000';

        $cmd = new Compact($this->stubCompactor(), $store);
        $cmd->execute(['1'], $ctx); // keep 1 轮 = 2 条消息

        $msgs = $c->messages();
        self::assertCount(3, $msgs); // 摘要 + q3/a3
        self::assertNotNull($store->load('20261008-090000')); // 压缩后自动 save
    }

    public function testCompactTooFewMessagesNoop(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $ctx->agent->conversation();
        $c->append(['role' => 'user', 'content' => 'only q']);
        $c->append(['role' => 'assistant', 'content' => 'only a']);

        (new Compact($this->stubCompactor()))->execute([], $ctx);

        self::assertCount(2, $c->messages()); // 不足 keep 轮,不动
    }

    // ---------- /rewind ----------

    public function testRewindToSelectedCheckpoint(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $this->multiTurnConversation($ctx->agent);

        $input = $this->inputFeeding(['2']); // 选第 2 个检查点(q2,index 2)
        $ctx->input = $input;
        (new Rewind())->execute([], $ctx);

        $msgs = $c->messages();
        self::assertCount(2, $msgs); // [0..2) = q1/a1
        self::assertSame('a1', $msgs[1]['content']);
        self::assertSame(40, $c->usage()['total_tokens']); // usage 不重置
    }

    public function testRewindInvalidSelectionKeepsConversation(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $this->multiTurnConversation($ctx->agent);
        $ctx->input = $this->inputFeeding(['9']);

        (new Rewind())->execute([], $ctx);

        self::assertCount(6, $c->messages()); // 非法序号,不动
    }

    // ---------- /clear ----------

    public function testClearDeletesSessionAndGeneratesNewId(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $ctx->agent->conversation();
        $c->append(['role' => 'user', 'content' => 'x']);
        $store = new FileConversationStore($this->dir . '/sessions');
        $ctx->currentSessionId = 'old-id';

        (new Clear($store))->execute([], $ctx);

        self::assertNull($store->load('old-id')); // 旧 session 已删
        self::assertNotSame('old-id', $ctx->currentSessionId); // 新 id
        self::assertSame([], $c->messages());
    }

    // ---------- /resume ----------

    public function testResumeListsWhenNoArgs(): void
    {
        $ctx = $this->context();
        $store = new FileConversationStore($this->dir . '/sessions');
        $store->save('s-20261008-a', (new Conversation())->snapshot());

        $exit = (new Resume($store))->execute([], $ctx); // 列表形态,不恢复

        self::assertTrue($exit); // 渲染列表不抛、不退出 REPL
    }

    public function testResumeRestoresSnapshot(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $store = new FileConversationStore($this->dir . '/sessions');
        $c = new Conversation(['role' => 'system', 'content' => 'sys']);
        $c->append(['role' => 'user', 'content' => 'past message']);
        $c->accumulateUsage((object) ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10]);
        $store->save('s-x', $c->snapshot());
        $ctx->currentSessionId = 's-current';

        (new Resume($store))->execute(['s-x'], $ctx);

        $msgs = $ctx->agent->conversation()->messages();
        self::assertSame('past message', $msgs[0]['content']);
        self::assertSame(['role' => 'system', 'content' => 'sys'], $ctx->agent->conversation()->system());
        self::assertSame(10, $ctx->agent->conversation()->usage()['total_tokens']);
        self::assertSame('s-x', $ctx->currentSessionId);
    }

    // ---------- /memory ----------

    public function testMemoryRememberInjectsSystemBlockNextTurn(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $memory = new Memory($this->dir . '/memory.json');
        $ctx->memory = $memory;

        (new MemoryCommand())->execute(['remember', 'user', 'likes', 'php74'], $ctx);

        self::assertSame('user likes php74', $memory->recall()[0]['text']); // 子命令 + 参数拼接
        // setSystem 即时生效:forRequest 含记忆块
        $memory->remember('another fact');
        $ctx->applyMemoryToSystem();
        $req = $ctx->agent->conversation()->forRequest();
        $systemContent = (string) ($req[0]['content'] ?? '');
        self::assertStringContainsString('likes php74', $systemContent);
    }

    public function testMemoryListAndForget(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $memory = new Memory($this->dir . '/memory.json');
        $memory->remember('m1');
        $memory->remember('m2');
        $ctx->memory = $memory;

        (new MemoryCommand())->execute(['list'], $ctx);
        (new MemoryCommand())->execute(['forget', '2'], $ctx); // 1-based 序号

        self::assertSame(['m1'], array_column($memory->recall(), 'text'));
    }

    // ---------- 自动压缩(Compact::maybeAuto) ----------

    public function testAutoCompactTriggersWhenOverBudget(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $this->multiTurnConversation($ctx->agent);
        // 极小窗口强制触发(window 60 → 预算 50,80% = 40 tokens;6 条消息估算 68 ≥ 40)
        $ctx->budget = new \Ws\Http\NanoGpt\ContextBudget(60, 10);
        $ctx->compactAuto = true;

        $fired = Compact::maybeAuto($ctx, $this->stubCompactor());

        self::assertTrue($fired);
        self::assertStringStartsWith('【历史摘要】', (string) $c->messages()[0]['content']);
    }

    public function testAutoCompactDisabledOrUnderBudget(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $this->multiTurnConversation($ctx->agent);
        $ctx->budget = new \Ws\Http\NanoGpt\ContextBudget(128000, 4096);
        $ctx->compactAuto = false; // 关闭

        self::assertFalse(Compact::maybeAuto($ctx, $this->stubCompactor()));

        $ctx->compactAuto = true; // 开启但远未超预算
        self::assertFalse(Compact::maybeAuto($ctx, $this->stubCompactor()));
    }

    // ---------- /context 双口径 ----------

    public function testContextShowsDualUsage(): void
    {
        $ctx = $this->context();
        $ctx->agent = $this->agentWithConversation();
        $c = $this->multiTurnConversation($ctx->agent);
        $ctx->budget = new \Ws\Http\NanoGpt\ContextBudget(128000, 4096);
        $ctx->modelSource = new class implements ModelSourceInterface {
            public function models(): array
            {
                return [new ModelInfo('m', 'Model M', '')];
            }
        };

        (new \CcGpt\Command\ShowContext())->execute([], $ctx); // 渲染不抛即通过(Output 落内存流)
        $this->expectNotToPerformAssertions();
    }

    // ---------- 工具 ----------

    private function inputFeeding(array $lines): Input
    {
        // 复用 Input 的 stream 注入:内存流逐行喂入(每行附 \n,末尾 EOF)
        $stream = fopen('php://memory', 'w+', false);
        fwrite($stream, implode("\n", $lines) . "\n");
        rewind($stream);

        return new Input($stream);
    }
}
