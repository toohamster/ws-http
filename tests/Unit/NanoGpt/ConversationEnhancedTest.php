<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\Conversation;

/**
 * design/24 §2(M1-a):Conversation 增强——setSystem/truncateFrom/snapshot/restore/userCheckpoints。
 *
 * 约束钉住:truncateFrom 仅 user 边界(605);rewind 不动 usage;snapshot v:1 往返;未知版本 611;
 * 摘要消息(role=user,content 前缀【历史摘要】)标记 isSummary。
 */
final class ConversationEnhancedTest extends TestCase
{
    /**
     * 造一条带工具调用对的会话:user → assistant(tool_calls) → tool → assistant(文本) → user。
     */
    private function conversationWithToolPair(): Conversation
    {
        $c = new Conversation();
        $c->append(['role' => 'user', 'content' => 'first question']);
        $c->append([
            'role'       => 'assistant',
            'content'    => null,
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{}']],
            ],
        ]);
        $c->append(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'file body']);
        $c->append(['role' => 'assistant', 'content' => 'answer one']);
        $c->append(['role' => 'user', 'content' => 'second question']);

        return $c;
    }

    // ---------- setSystem ----------

    public function testSetSystemRuntimeEffective(): void
    {
        $c = new Conversation();
        $c->append(['role' => 'user', 'content' => 'q1']);

        $c->setSystem(['role' => 'system', 'content' => 'new persona']);

        self::assertSame(['role' => 'system', 'content' => 'new persona'], $c->system());
        $req = $c->forRequest();
        self::assertSame('new persona', $req[0]['content']); // 下一轮即生效
        self::assertCount(2, $req);
    }

    public function testSetSystemNullRemovesSystem(): void
    {
        $c = new Conversation(['role' => 'system', 'content' => 's']);
        $c->setSystem(null);

        self::assertNull($c->system());
        self::assertSame($c->messages(), $c->forRequest());
    }

    // ---------- truncateFrom(user 边界约束) ----------

    public function testTruncateFromUserBoundary(): void
    {
        $c = $this->conversationWithToolPair();

        // index 4 = 第二条 user 消息 → 截断后保留 [0..4)
        $c->truncateFrom(4);

        $msgs = $c->messages();
        self::assertCount(4, $msgs);
        self::assertSame('answer one', $msgs[3]['content']); // assistant 文本保留
    }

    public function testTruncateFromBoundaryBeforeToolPairKeepsPair(): void
    {
        $c = $this->conversationWithToolPair();

        // index 1(第一条 user 之后的 assistant tool_calls 消息)非 user 边界 → 605
        try {
            $c->truncateFrom(1);
            self::fail('expected AgentException 605');
        } catch (AgentException $e) {
            self::assertSame(605, $e->getCode());
        }
        // 原 messages 不动(先算后换)
        self::assertCount(5, $c->messages());
    }

    public function testTruncateFromZeroClearsMessagesKeepsUsage(): void
    {
        $c = $this->conversationWithToolPair();
        $c->accumulateUsage((object) ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]);

        $c->truncateFrom(0);

        self::assertSame([], $c->messages());
        // rewind 只动 messages,不动 usage
        self::assertSame(15, $c->usage()['total_tokens']);
    }

    public function testTruncateFromRejectsToolMessageIndex(): void
    {
        $c = $this->conversationWithToolPair();

        // index 2 = role=tool 消息(工具对内部)→ 605
        try {
            $c->truncateFrom(2);
            self::fail('expected AgentException 605');
        } catch (AgentException $e) {
            self::assertSame(605, $e->getCode());
        }
    }

    public function testTruncateFromRejectsAssistantToolCallsIndex(): void
    {
        $c = $this->conversationWithToolPair();

        // index 3 = assistant 文本消息,其后没有未闭合工具对,但非 user 边界 → 605
        try {
            $c->truncateFrom(3);
            self::fail('expected AgentException 605');
        } catch (AgentException $e) {
            self::assertSame(605, $e->getCode());
        }
    }

    public function testTruncateFromOutOfRangeIndex(): void
    {
        $c = $this->conversationWithToolPair();

        try {
            $c->truncateFrom(99);
            self::fail('expected AgentException 605');
        } catch (AgentException $e) {
            self::assertSame(605, $e->getCode());
        }
    }

    // ---------- userCheckpoints ----------

    public function testUserCheckpointsListsUserMessages(): void
    {
        $c = $this->conversationWithToolPair();

        $cps = $c->userCheckpoints();

        self::assertCount(2, $cps);
        self::assertSame(0, $cps[0]['index']);
        self::assertFalse($cps[0]['isSummary']);
        self::assertSame('first question', $cps[0]['prefix']);
        self::assertSame(4, $cps[1]['index']);
        self::assertSame('second question', $cps[1]['prefix']);
    }

    public function testUserCheckpointsMarksSummaryMessage(): void
    {
        $c = new Conversation();
        $c->append(['role' => 'user', 'content' => 'old question']);
        $c->append(['role' => 'assistant', 'content' => 'old answer']);
        // 压缩摘要消息:role=user,content 前缀【历史摘要】
        $c->append(['role' => 'user', 'content' => '【历史摘要】task was x; done y; pending z']);

        $cps = $c->userCheckpoints();

        self::assertCount(2, $cps);
        self::assertFalse($cps[0]['isSummary']);
        self::assertTrue($cps[1]['isSummary']); // [摘要] 标记,可选为截断点
        self::assertSame(2, $cps[1]['index']);
    }

    public function testUserCheckpointsPrefixTruncatedToWidth(): void
    {
        $c = new Conversation();
        $c->append(['role' => 'user', 'content' => '一个很长的用户输入超过四十个字符宽度限制的内容需要被截断以便在列表里展示']);

        $cps = $c->userCheckpoints(10);

        self::assertLessThanOrEqual(13, mb_strlen($cps[0]['prefix'])); // 截断 + 可能省略号
        self::assertStringContainsString('一个很长的', $cps[0]['prefix']);
    }

    // ---------- snapshot / restore ----------

    public function testSnapshotRestoreRoundtrip(): void
    {
        $c = new Conversation(['role' => 'system', 'content' => 's1']);
        $c->append(['role' => 'user', 'content' => 'q1']);
        $c->append(['role' => 'assistant', 'content' => 'a1']);
        $c->accumulateUsage((object) ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10]);

        $snap = $c->snapshot();

        self::assertSame(1, $snap['v']);
        $r = Conversation::restore($snap);

        self::assertSame(['role' => 'system', 'content' => 's1'], $r->system());
        self::assertSame($c->messages(), $r->messages());
        self::assertSame($c->usage(), $r->usage());
        self::assertSame($c->forRequest(), $r->forRequest());
    }

    public function testRestoreUnknownVersionThrows611(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionCode(611);

        Conversation::restore(['v' => 999, 'messages' => [], 'usage' => [], 'system' => null]);
    }

    public function testRestoreMinimalSnapshot(): void
    {
        $r = Conversation::restore(['v' => 1, 'messages' => [], 'usage' => ['total_tokens' => 0], 'system' => null]);

        self::assertSame([], $r->messages());
        self::assertNull($r->system());
    }
}
