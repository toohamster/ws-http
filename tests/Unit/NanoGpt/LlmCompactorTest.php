<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\ChatResult;
use Ws\Http\NanoGpt\LlmCompactor;
use Ws\Http\Tests\Fake\FakeChatTransport;

/**
 * design/24 §4.2(M2-b;design/28 D3 契约切换):LlmCompactor 经 FakeChatTransport
 * 替身(tests/Fake 实名规格,同 AgentTest)。
 *
 * 钉住:摘要消息形态(role=user,content 前缀【历史摘要】);tool 消息预截断 2000 字符
 * (只影响摘要 prompt,原 messages 不动);LLM 失败/空段/坏响应 → 612;model/max_tokens
 * 经 options 透传。
 */
final class LlmCompactorTest extends TestCase
{
    public function testCompactProducesSummaryUserMessage(): void
    {
        $transport = new FakeChatTransport([$this->chatResult('任务目标:读文件;结论:x;未完成:y;数值:z=1')]);
        $compactor = new LlmCompactor($transport, 'deepseek-v4-flash');

        $summary = $compactor->compact([
            ['role' => 'user', 'content' => 'do task'],
            ['role' => 'assistant', 'content' => 'partially done'],
        ]);

        self::assertSame('user', $summary['role']);
        self::assertStringStartsWith('【历史摘要】', $summary['content']);
        self::assertStringContainsString('任务目标:读文件', $summary['content']);
    }

    public function testToolMessagesTruncatedTo2000CharsInPromptOnly(): void
    {
        $transport = new FakeChatTransport([$this->chatResult('summary text')]);
        $compactor = new LlmCompactor($transport, 'm');

        $longToolContent = str_repeat('T', 5000);
        $original = [
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => $longToolContent],
        ];
        $compactor->compact($original);

        // 进入压缩 prompt 的 tool 内容被截断到 2000 字符(transcript 形态:tool 行并入 user 消息)
        self::assertCount(1, $transport->calls);
        $messages = $transport->calls[0]['messages'];

        $userContent = '';
        foreach ($messages as $m) {
            if ($m['role'] === 'user') {
                $userContent = (string) $m['content'];
            }
        }
        self::assertStringContainsString('[tool] ' . str_repeat('T', 2000), $userContent); // 截断到 2000
        self::assertStringNotContainsString(str_repeat('T', 2001), $userContent);
        // 原 messages 不动(先算后换)
        self::assertSame(5000, strlen($original[1]['content']));
    }

    public function testTransportFailureThrows612(): void
    {
        $transport = new FakeChatTransport([], new AgentException('transport server error: boom', 622));
        $compactor = new LlmCompactor($transport, 'm');

        try {
            $compactor->compact([['role' => 'user', 'content' => 'q']]);
            self::fail('expected AgentException 612');
        } catch (AgentException $e) {
            self::assertSame(612, $e->getCode());
        }
    }

    public function testEmptySegmentThrows612(): void
    {
        $compactor = new LlmCompactor(new FakeChatTransport([]), 'm');

        try {
            $compactor->compact([]);
            self::fail('expected AgentException 612');
        } catch (AgentException $e) {
            self::assertSame(612, $e->getCode());
        }
    }

    public function testEmptyContentReplyThrows612(): void
    {
        $compactor = new LlmCompactor(new FakeChatTransport([$this->chatResult('')]), 'm');

        try {
            $compactor->compact([['role' => 'user', 'content' => 'q']]);
            self::fail('expected AgentException 612');
        } catch (AgentException $e) {
            self::assertSame(612, $e->getCode());
        }
    }

    public function testSummaryMaxTokensAndModelPassedAsOptions(): void
    {
        $transport = new FakeChatTransport([$this->chatResult('ok')]);
        $compactor = new LlmCompactor($transport, 'm', 512);

        $compactor->compact([['role' => 'user', 'content' => 'q']]);

        self::assertSame('m', $transport->calls[0]['options']['model']);
        self::assertSame(512, $transport->calls[0]['options']['max_tokens']);
    }

    // ---------- 数据 ----------

    private function chatResult(string $content): ChatResult
    {
        return new ChatResult($content, null, 'stop', [], [
            'prompt_tokens'     => 1,
            'completion_tokens' => 1,
            'total_tokens'      => 2,
        ]);
    }
}
