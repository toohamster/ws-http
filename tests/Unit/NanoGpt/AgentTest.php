<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Tool\WriteFileTool;
use Ws\Http\Plugin\OpenAI\Client;
use Ws\Http\Request;
use Ws\Http\RequestOptions;
use Ws\Http\Response;

/**
 * design/21 N2:Agent loop(mock 双响应序列,同 FakeRequestFactory 替身模式)。
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
     * 替身 Client:按调用序号出队预制 body(捕获请求 payload 供断言)。
     *
     * @param array<int, object> $bodies chat/completions 响应 body 序列
     * @param array<int, mixed>|null $capturedPayloads 引用传出的请求 payload 序列
     */
    private function fakeClient(array $bodies, &$capturedPayloads = null): Client
    {
        $captured = &$capturedPayloads;
        $http = new class($bodies, $captured) extends Request {
            /** @var array<int, object> */
            private $bodies;

            /** @var int 静态序号:withOptions 会 clone Request 实例,实例属性会随之复制导致队列错乱 */
            public static $index = 0;

            /** @var array<int, mixed>|null */
            public $payloads;

            public function __construct(array $bodies, &$capturedPayloads)
            {
                parent::__construct();
                $this->bodies = $bodies;
                self::$index = 0;
                $this->payloads = &$capturedPayloads;
            }

            public function send(string $method, string $url, $body = null, array $headers = []): Response
            {
                $content = $body !== null && isset($body->content) ? (string) $body->content : '{}';
                if (\is_array($this->payloads)) {
                    $this->payloads[] = json_decode($content, true);
                }
                $bodyObj = $this->bodies[self::$index] ?? (object) [];
                self::$index++;

                return new Response(
                    ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1],
                    json_encode($bodyObj) ?: '{}',
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n"
                );
            }
        };

        return new Client('test-key', $http, 'https://fake.local/v1');
    }

    private function textBody(string $content): object
    {
        return (object) [
            'choices' => [
                (object) [
                    'message'       => (object) ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => (object) ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    private function toolCallBody(string $callId, string $name, array $args): object
    {
        return (object) [
            'choices' => [
                (object) [
                    'message'       => (object) [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => [
                            (object) [
                                'id'       => $callId,
                                'type'     => 'function',
                                'function' => (object) ['name' => $name, 'arguments' => json_encode($args)],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => (object) ['prompt_tokens' => 20, 'completion_tokens' => 8, 'total_tokens' => 28],
        ];
    }

    public function testPlainTextTurn(): void
    {
        $agent = new Agent($this->fakeClient([$this->textBody('你好!')]), 'test-model');

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
        $payloads = [];
        $client = $this->fakeClient([
            $this->toolCallBody('call-1', 'write_file', ['path' => 'a.txt', 'content' => 'data']),
            $this->textBody('done'),
        ], $payloads);

        $agent = new Agent($client, 'test-model');
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
        self::assertCount(2, $payloads);
        $roles = array_column($payloads[1]['messages'], 'role');
        self::assertSame(['user', 'assistant', 'tool'], $roles);
        self::assertSame('call-1', $payloads[1]['messages'][2]['tool_call_id']);

        // usage 跨两次响应累积
        self::assertSame(30, $agent->conversation()->usage()['prompt_tokens']);
        self::assertSame(43, $agent->conversation()->usage()['total_tokens']);
    }

    public function testUnknownToolBackfillsErrorNotAbort(): void
    {
        $payloads = [];
        $client = $this->fakeClient([
            $this->toolCallBody('call-1', 'no_such_tool', []),
            $this->textBody('ok, adjusted'),
        ], $payloads);

        $agent = new Agent($client, 'test-model'); // 无工具注册

        $events = iterator_to_array($agent->run('go'));

        self::assertSame('tool_call', $events[0]['type']);
        self::assertStringContainsString('unknown tool', $events[1]['result']);
        self::assertSame('text', $events[2]['type']);
        self::assertStringContainsString('unknown tool', (string) $payloads[1]['messages'][2]['content']);
    }

    public function testMaxTurnsThrows601(): void
    {
        // 每轮都回 tool_calls → 永不产出文本
        $client = $this->fakeClient([
            $this->toolCallBody('c1', 'write_file', ['path' => 'a', 'content' => 'x']),
            $this->toolCallBody('c2', 'write_file', ['path' => 'b', 'content' => 'x']),
        ]);

        $agent = new Agent($client, 'test-model', 2);
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
        $body = $this->textBody('partial');
        $body->choices[0]->finish_reason = 'length';

        $agent = new Agent($this->fakeClient([$body]), 'test-model');

        $events = iterator_to_array($agent->run('hi'));

        self::assertSame('error', $events[0]['type']);
        self::assertStringContainsString('truncated', $events[0]['message']);
        self::assertSame('text', $events[1]['type']);
    }

    public function testTransportErrorYieldsErrorAndKeepsConversation(): void
    {
        $http = new class extends Request {
            public function send(string $method, string $url, $body = null, array $headers = []): Response
            {
                throw new \Ws\Http\RequestException(7, 'connection refused', 'POST', 'https://fake.local/v1/chat/completions');
            }
        };

        $agent = new Agent(new Client('k', $http, 'https://fake.local/v1'), 'test-model');

        $events = iterator_to_array($agent->run('hi'));

        self::assertSame('error', $events[0]['type']);
        self::assertStringContainsString('connection refused', $events[0]['message']);
        // 会话保留:user 消息仍在,可重试
        self::assertSame('user', $agent->conversation()->messages()[0]['role']);
    }

    public function testUnexpectedStructureThrows604(): void
    {
        $agent = new Agent($this->fakeClient([(object) ['foo' => 'bar']]), 'test-model');

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(604);
        iterator_to_array($agent->run('hi'));
    }

    public function testToolsOmittedFromPayloadWhenEmpty(): void
    {
        $payloads = [];
        $client = $this->fakeClient([$this->textBody('hi')], $payloads);

        $agent = new Agent($client, 'test-model');
        iterator_to_array($agent->run('hi'));

        self::assertArrayNotHasKey('tools', $payloads[0]);
    }
}
