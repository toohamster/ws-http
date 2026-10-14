<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\ChatResult;
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Tool\WriteFileTool;
use Ws\Http\Tests\Fake\FakeChatTransport;

/**
 * design/21 N2:Agent loop(design/28 D2 修订:替身从 Client 换为 FakeChatTransport——
 * 编排层只依赖传输契约;断言不变,等价性验证)。
 */
final class AgentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ws-agent-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * 替身传输:按序出队预制 ChatResult(calls 捕获历次 messages/options 供断言)。
     *
     * @param array<int, ChatResult> $results
     */
    private function fakeTransport(array $results): FakeChatTransport
    {
        return new FakeChatTransport($results);
    }

    private function textResult(string $content): ChatResult
    {
        return new ChatResult($content, null, 'stop', [], [
            'prompt_tokens'     => 10,
            'completion_tokens' => 5,
            'total_tokens'      => 15,
        ]);
    }

    /**
     * @param array<int, array{id: string, name: string, arguments: string}> $calls
     */
    private function toolCallResult(string $callId, string $name, array $args): ChatResult
    {
        $arguments = json_encode($args) ?: '{}';

        return new ChatResult('', null, 'tool_calls', [
            ['id' => $callId, 'name' => $name, 'arguments' => $arguments, 'wire' => (object) [
                'id'       => $callId,
                'type'     => 'function',
                'function' => (object) ['name' => $name, 'arguments' => $arguments],
            ]],
        ], [
            'prompt_tokens'     => 20,
            'completion_tokens' => 8,
            'total_tokens'      => 28,
        ]);
    }

    public function testPlainTextTurn(): void
    {
        $agent = new Agent($this->fakeTransport([$this->textResult('你好!')]), 'test-model');

        $events = iterator_to_array($agent->run('hi'));

        self::assertSame('text', $events[0]['type']);
        self::assertSame('你好!', $events[0]['text']);
        self::assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], $agent->conversation()->usage());
        $messages = $agent->conversation()->messages();
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('assistant', $messages[1]['role']);
    }

    public function testToolCallLoopWithBackfill(): void
    {
        $transport = $this->fakeTransport([
            $this->toolCallResult('call-1', 'write_file', ['path' => 'a.txt', 'content' => 'data']),
            $this->textResult('done'),
        ]);

        $agent = new Agent($transport, 'test-model');
        $agent->tools()->register(new WriteFileTool(new Sandbox($this->root)));

        $events = iterator_to_array($agent->run('写个文件'));

        // 事件序列:tool_call → tool_result → text
        self::assertSame('tool_call', $events[0]['type']);
        self::assertSame('write_file', $events[0]['name']);
        self::assertSame('tool_result', $events[1]['type']);
        self::assertStringStartsWith('ok:', $events[1]['result']);
        self::assertSame('text', $events[2]['type']);
        self::assertFileExists($this->root . '/a.txt');

        // 第二次请求的 messages 含 role=tool 回填
        self::assertCount(2, $transport->calls);
        $secondMessages = $transport->calls[1]['messages'];
        $roles = array_column($secondMessages, 'role');
        self::assertSame(['user', 'assistant', 'tool'], $roles);
        self::assertSame('call-1', $secondMessages[2]['tool_call_id']);

        // usage 跨两次响应累积
        self::assertSame(30, $agent->conversation()->usage()['prompt_tokens']);
        self::assertSame(43, $agent->conversation()->usage()['total_tokens']);
    }

    public function testUnknownToolBackfillsErrorNotAbort(): void
    {
        $transport = $this->fakeTransport([
            $this->toolCallResult('call-1', 'no_such_tool', []),
            $this->textResult('ok, adjusted'),
        ]);

        $agent = new Agent($transport, 'test-model'); // 无工具注册

        $events = iterator_to_array($agent->run('go'));

        self::assertSame('tool_call', $events[0]['type']);
        self::assertStringContainsString('unknown tool', $events[1]['result']);
        self::assertSame('text', $events[2]['type']);
        self::assertStringContainsString('unknown tool', (string) $transport->calls[1]['messages'][2]['content']);
    }

    public function testMaxTurnsThrows601(): void
    {
        // 每轮都回 tool_calls → 永不产出文本
        $transport = $this->fakeTransport([
            $this->toolCallResult('c1', 'write_file', ['path' => 'a', 'content' => 'x']),
            $this->toolCallResult('c2', 'write_file', ['path' => 'b', 'content' => 'x']),
        ]);

        $agent = new Agent($transport, 'test-model', 2);
        $agent->tools()->register(new WriteFileTool(new Sandbox($this->root)));

        try {
            iterator_to_array($agent->run('loop'));
            self::fail('expected AgentException 601');
        } catch (AgentException $e) {
            self::assertSame(601, $e->getCode());
        }
    }

    public function testFinishReasonLengthYieldsError(): void
    {
        $agent = new Agent($this->fakeTransport([new ChatResult('partial', null, 'length')]), 'test-model');

        $events = iterator_to_array($agent->run('hi'));

        self::assertSame('error', $events[0]['type']);
        self::assertStringContainsString('truncated', $events[0]['message']);
        self::assertSame('text', $events[1]['type']);
    }

    public function testTransportErrorYieldsErrorAndKeepsConversation(): void
    {
        // 传输失败(RequestException → Transport 分类为 624 族;此处直接以分类异常注入)
        $transport = new FakeChatTransport([], new AgentException('transport failure: connection refused', 624));

        $agent = new Agent($transport, 'test-model');

        $events = iterator_to_array($agent->run('hi'));

        self::assertSame('error', $events[0]['type']);
        self::assertSame(624, $events[0]['code']); // 分类 code 透传 error 事件
        self::assertStringContainsString('connection refused', $events[0]['message']);
        // 会话保留:user 消息仍在,可重试
        self::assertSame('user', $agent->conversation()->messages()[0]['role']);
    }

    public function testTransportClassificationCodesSurfaceInEvents(): void
    {
        // 五类分类码逐一透传(design/28 §4:编排层可对分类分支)
        foreach ([620, 621, 622, 623, 624] as $code) {
            $transport = new FakeChatTransport([], new AgentException(sprintf('classified %d', $code), $code));

            $agent = new Agent($transport, 'test-model');
            $events = iterator_to_array($agent->run('hi'));

            self::assertSame($code, $events[0]['code'], sprintf('code %d should surface', $code));
        }
    }

    public function testToolsPassedToTransportWhenRegistered(): void
    {
        $transport = $this->fakeTransport([$this->textResult('hi')]);

        $agent = new Agent($transport, 'test-model');
        $agent->tools()->register(new WriteFileTool(new Sandbox($this->root)));
        iterator_to_array($agent->run('hi'));

        self::assertArrayHasKey('tools', $transport->calls[0]['options']);
    }

    public function testModelPassedInRequestOptions(): void
    {
        $transport = $this->fakeTransport([$this->textResult('hi')]);

        $agent = new Agent($transport, 'my-model-x');
        iterator_to_array($agent->run('hi'));

        self::assertSame('my-model-x', $transport->calls[0]['options']['model']);
    }

    public function testToolsOmittedFromPayloadWhenEmpty(): void
    {
        $transport = $this->fakeTransport([$this->textResult('hi')]);

        $agent = new Agent($transport, 'test-model');
        iterator_to_array($agent->run('hi'));

        self::assertArrayNotHasKey('tools', $transport->calls[0]['options']);
    }
}
