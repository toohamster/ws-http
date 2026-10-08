<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\LlmCompactor;
use Ws\Http\Plugin\OpenAI\Client as OpenAIClient;
use Ws\Http\Request;
use Ws\Http\RequestOptions;
use Ws\Http\Response;
use Ws\Http\Tests\Engine\FakeRequestFactory;

/**
 * design/24 §4.2(M2-b):LlmCompactor——经既有测试替身(FakeRequestFactory + 匿名 Request 覆写
 * send,同 PluginRegistryTest 的 requestOn 模式)。
 *
 * 钉住:摘要消息形态(role=user,content 前缀【历史摘要】);tool 消息预截断 2000 字符
 * (只影响摘要 prompt,原 messages 不动);LLM 失败/空段/坏响应 → 612。
 */
final class LlmCompactorTest extends TestCase
{
    public function testCompactProducesSummaryUserMessage(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->chatResponse('任务目标:读文件;结论:x;未完成:y;数值:z=1'));
        $compactor = new LlmCompactor($this->clientOn($factory), 'deepseek-v4-flash');

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
        $factory = new FakeRequestFactory();
        $factory->queue($this->chatResponse('summary text'));
        $compactor = new LlmCompactor($this->clientOn($factory), 'm');

        $longToolContent = str_repeat('T', 5000);
        $original = [
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => $longToolContent],
        ];
        $compactor->compact($original);

        // 进入压缩 prompt 的 tool 内容被截断到 2000 字符(transcript 形态:tool 行并入 user 消息)
        $sent = $factory->lastSent();
        self::assertNotNull($sent);
        $payload = json_decode((string) $sent['body'], true);
        self::assertIsArray($payload, 'chat request body should be JSON: ' . substr((string) $sent['body'], 0, 200));

        $userContent = '';
        foreach ($payload['messages'] as $m) {
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
        $factory = new FakeRequestFactory();
        $factory->queue(new Response(
            ['http_code' => 500, 'header_size' => 0, 'total_time' => 0.1],
            '{"error":{"message":"boom"}}',
            "HTTP/1.1 500\r\nContent-Type: application/json\r\n"
        ));
        $compactor = new LlmCompactor($this->clientOn($factory), 'm');

        try {
            $compactor->compact([['role' => 'user', 'content' => 'q']]);
            self::fail('expected AgentException 612');
        } catch (AgentException $e) {
            self::assertSame(612, $e->getCode());
        }
    }

    public function testEmptySegmentThrows612(): void
    {
        $factory = new FakeRequestFactory();
        $compactor = new LlmCompactor($this->clientOn($factory), 'm');

        try {
            $compactor->compact([]);
            self::fail('expected AgentException 612');
        } catch (AgentException $e) {
            self::assertSame(612, $e->getCode());
        }
    }

    public function testEmptyContentReplyThrows612(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->chatResponse(''));
        $compactor = new LlmCompactor($this->clientOn($factory), 'm');

        try {
            $compactor->compact([['role' => 'user', 'content' => 'q']]);
            self::fail('expected AgentException 612');
        } catch (AgentException $e) {
            self::assertSame(612, $e->getCode());
        }
    }

    public function testSummaryMaxTokensPassedAsExtra(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->chatResponse('ok'));
        $compactor = new LlmCompactor($this->clientOn($factory), 'm', 512);

        $compactor->compact([['role' => 'user', 'content' => 'q']]);

        $payload = json_decode((string) $factory->lastSent()['body'], true);
        self::assertIsArray($payload, 'chat request body should be JSON');
        self::assertSame(512, $payload['max_tokens']);
    }

    // ---------- 替身(同 PluginRegistryTest::requestOn 模式) ----------

    private function clientOn(FakeRequestFactory $factory): OpenAIClient
    {
        $request = new class($factory) extends Request {
            private $factory;

            public function __construct(FakeRequestFactory $factory)
            {
                parent::__construct();
                $this->factory = $factory;
            }

            public function send(string $method, string $url, $body = null, array $headers = []): Response
            {
                $this->factory->record($method, $url, $body, $headers);

                $responses = $this->factory->responses;

                return $responses !== [] ? $responses[count($responses) - 1] : new Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '{}', '');
            }
        };

        $options = (new RequestOptions())
            ->withDefaultHeaders(['Authorization' => 'Bearer sk-test', 'Content-Type' => 'application/json']);
        $sealed = $request->withOptions(static fn (RequestOptions $o): RequestOptions => $options);

        return new OpenAIClient('sk-test', $sealed, 'https://unit.invalid/v1');
    }

    private function chatResponse(string $content): Response
    {
        return new Response(
            ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1],
            json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
        );
    }
}
