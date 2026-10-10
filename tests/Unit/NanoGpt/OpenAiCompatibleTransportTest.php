<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\ChatResult;
use Ws\Http\NanoGpt\Transport\ChatTransportInterface;
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;
use Ws\Http\NanoGpt\Transport\OpenAiCompatibleTransport;
use Ws\Http\NanoGpt\Transport\OrcaRouterTransport;
use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Http\Tests\Fake\FakeChatRequest;

/**
 * design/28 D1:OpenAiCompatibleTransport(协议族基类)——重试完整语义/错误分类
 * 620–624/config 归一/差异槽;厂商类常量归一。
 *
 * 替身:tests/Fake/FakeChatRequest(实名规格,按状态码序列分发;非匿名类)。
 */
final class OpenAiCompatibleTransportTest extends TestCase
{
    // ---------- chat happy path ----------

    public function testChatHappyPathReturnsChatResult(): void
    {
        $fake = new FakeChatRequest([[200, $this->chatBody('pong', 104)]]);
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        $result = $transport->chat([['role' => 'user', 'content' => 'hi']]);

        self::assertInstanceOf(ChatResult::class, $result);
        self::assertSame('pong', $result->content());
        self::assertSame(104, $result->usage()['total_tokens']);
    }

    public function testChatUsesVendorDefaultModel(): void
    {
        $fake = new FakeChatRequest([[200, $this->chatBody('ok')]]);
        $transport = new DeepSeekTransport(['api_key' => 'sk-test', 'http' => $fake]);

        $transport->chat([['role' => 'user', 'content' => 'hi']]);

        $payload = json_decode((string) $fake->lastBody(), true);
        self::assertSame('deepseek-chat', $payload['model']);
        self::assertStringStartsWith('https://api.deepseek.com/v1', $fake->sent()[0]['url']);
    }

    public function testSamplingOptionsPassed(): void
    {
        $fake = new FakeChatRequest([[200, $this->chatBody('ok')]]);
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        $transport->chat([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 256, 'temperature' => 0.5, 'retry' => ['max' => 0]]);

        $payload = json_decode((string) $fake->lastBody(), true);
        self::assertSame(256, $payload['max_tokens']);
        self::assertSame(0.5, $payload['temperature']);
        self::assertArrayNotHasKey('retry', $payload); // 策略不进请求体
    }

    // ---------- 重试语义(§3) ----------

    public function testNoRetryByDefault(): void
    {
        $fake = new FakeChatRequest([[500, '{"error":{"message":"boom"}}']]);
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        try {
            $transport->chat([['role' => 'user', 'content' => 'hi']]);
            self::fail('expected 622');
        } catch (AgentException $e) {
            self::assertSame(622, $e->getCode());
            self::assertSame(1, count($fake->sent())); // 只发一次
        }
    }

    public function testRetryOnConfiguredStatusThenSuccess(): void
    {
        $fake = new FakeChatRequest([
            [500, '{"error":{"message":"transient"}}'],
            [200, $this->chatBody('recovered')],
        ]);
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        $result = $transport->chat([['role' => 'user', 'content' => 'hi']], ['retry' => ['max' => 1, 'delay_ms' => 0]]);

        self::assertSame('recovered', $result->content());
        self::assertSame(2, count($fake->sent()));
    }

    public function testRetryExhaustedMapsToSemanticCode(): void
    {
        $fake = new FakeChatRequest([
            [429, '{"error":{"message":"slow down"}}'],
            [429, '{"error":{"message":"slow down"}}'],
        ]);
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        try {
            $transport->chat([['role' => 'user', 'content' => 'hi']], ['retry' => ['max' => 1, 'delay_ms' => 0]]);
            self::fail('expected 621');
        } catch (AgentException $e) {
            self::assertSame(621, $e->getCode());
        }
    }

    public function testRetryAfterHeaderTakesPrecedence(): void
    {
        // Retry-After: 7 头 → 退避 = 7s(头优先于指数)
        $m = new \ReflectionMethod(OpenAiCompatibleTransport::class, 'backoffMicroseconds');
        $m->setAccessible(true);
        $t = new OrcaRouterTransport(['api_key' => 'sk']);
        $withHeader = new Response(['http_code' => 429, 'header_size' => 0, 'total_time' => 0.1], '{}', "HTTP/1.1 429\r\nRetry-After: 7\r\n");

        self::assertSame(7000000, $m->invoke($t, 0, $withHeader));
    }

    public function testBackoffExponentialWithoutHeader(): void
    {
        $m = new \ReflectionMethod(OpenAiCompatibleTransport::class, 'backoffMicroseconds');
        $m->setAccessible(true);
        $t = new OrcaRouterTransport(['api_key' => 'sk']);
        $noHeader = new Response(['http_code' => 429, 'header_size' => 0, 'total_time' => 0.1], '{}', "HTTP/1.1 429\r\n");

        self::assertSame(1000000, $m->invoke($t, 0, $noHeader)); // 1000ms * 2^0
        self::assertSame(2000000, $m->invoke($t, 1, $noHeader)); // 1000ms * 2^1
        self::assertSame(4000000, $m->invoke($t, 2, $noHeader)); // 1000ms * 2^2
    }

    public function testNonRetryableStatusNotRetried(): void
    {
        // 401 不在 on_status → 即使 max=3 也立即 620
        $fake = new FakeChatRequest([[401, '{"error":{"message":"bad key"}}']]);
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        try {
            $transport->chat([['role' => 'user', 'content' => 'hi']], ['retry' => ['max' => 3, 'delay_ms' => 0]]);
            self::fail('expected 620');
        } catch (AgentException $e) {
            self::assertSame(620, $e->getCode());
            self::assertSame(1, count($fake->sent()));
        }
    }

    // ---------- 错误分类(§4 五码) ----------

    /**
     * @dataProvider statusMappingProvider
     */
    public function testStatusToSemanticCode(int $status, int $expected): void
    {
        $fake = new FakeChatRequest([[$status, '{"error":{"message":"x"}}']]);
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        try {
            $transport->chat([['role' => 'user', 'content' => 'hi']]);
            self::fail("expected code $expected");
        } catch (AgentException $e) {
            self::assertSame($expected, $e->getCode());
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public function statusMappingProvider(): array
    {
        return [
            '401 → 620' => [401, 620],
            '403 → 620' => [403, 620],
            '429 → 621' => [429, 621],
            '500 → 622' => [500, 622],
            '503 → 622' => [503, 622],
        ];
    }

    public function testUnexpected2xxStructureMaps624(): void
    {
        $fake = new FakeChatRequest([[200, '{"foo":"bar"}']]); // 2xx 但无 choices
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => $fake]);

        try {
            $transport->chat([['role' => 'user', 'content' => 'hi']]);
            self::fail('expected 624');
        } catch (AgentException $e) {
            self::assertSame(624, $e->getCode());
        }
    }

    // ---------- capabilities ----------

    public function testCapabilitiesReturnsModelList(): void
    {
        $modelsBody = json_encode(['data' => [
            ['id' => 'deepseek-chat', 'name' => 'DeepSeek Chat'],
            ['id' => 'deepseek-reasoner'],
        ]]);
        $fake = new FakeChatRequest([[200, (string) $modelsBody]]);
        $transport = new DeepSeekTransport(['api_key' => 'sk-test', 'http' => $fake]);

        $caps = $transport->capabilities();

        self::assertCount(2, $caps['models']);
        self::assertSame('deepseek-chat', $caps['models'][0]->id());
        self::assertSame('DeepSeek Chat', $caps['models'][0]->name());
        self::assertSame('deepseek-reasoner', $caps['models'][1]->id());
        self::assertNull($caps['contextWindow']);
    }

    public function testCapabilitiesFailureTolerated(): void
    {
        $transport = new OrcaRouterTransport(['api_key' => 'sk-test', 'http' => new Request()]);

        $caps = $transport->capabilities(); // 网络失败 → 空目录,不抛

        self::assertSame([], $caps['models']);
    }

    // ---------- 厂商类常量归一(每厂商一测) ----------

    public function testVendorConstantsNormalized(): void
    {
        foreach ([
            DeepSeekTransport::class   => 'deepseek-chat',
            OrcaRouterTransport::class => 'deepseek/deepseek-v4-flash-free',
        ] as $class => $model) {
            $t = new $class(['api_key' => 'sk']);
            $r = new \ReflectionMethod(OpenAiCompatibleTransport::class, 'defaultModel');
            $r->setAccessible(true);

            self::assertSame($model, $r->invoke($t));
            self::assertInstanceOf(ChatTransportInterface::class, $t);
        }
    }

    // ---------- 数据 ----------

    private function chatBody(string $content, int $usageTotal = 5): string
    {
        return json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => $usageTotal],
        ]);
    }
}
