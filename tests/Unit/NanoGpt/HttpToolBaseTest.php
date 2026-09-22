<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\Tool\ApiGetTool;
use Ws\Http\Request;
use Ws\Http\Response;

/**
 * design/25 C5a:HttpToolBase(防线/构造期校验/内网策略/重定向封堵)+ ApiGetTool 归位回归。
 */
final class HttpToolBaseTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, opts?: array}> */
    public static array $sent = [];

    protected function tearDown(): void
    {
        self::$sent = [];
    }

    /**
     * Request 替身:捕获 send + 记录 maxRedirects(重定向封堵断言)。
     * 注:PHP 7.4 无构造器属性提升,传统赋值。
     */
    private function fakeHttp(int $code = 200, string $body = '{}'): Request
    {
        return new class($code, $body) extends Request {
            /** @var int */
            private $code;

            /** @var string */
            private $body;

            public function __construct(int $code, string $body)
            {
                parent::__construct();
                $this->code = $code;
                $this->body = $body;
            }

            public function send(string $method, string $url, $body = null, array $headers = []): Response
            {
                HttpToolBaseTest::$sent[] = ['method' => $method, 'url' => $url, 'redirects' => $this->options()->maxRedirects()];

                return new Response(
                    ['http_code' => $this->code, 'header_size' => 0, 'total_time' => 0.1],
                    $this->body,
                    sprintf("HTTP/1.1 %d X\r\nContent-Type: application/json\r\n\r\n", $this->code)
                );
            }
        };
    }

    // ---------- 构造期校验 ----------

    public function testDisabledWhenNoHosts(): void
    {
        $result = (new ApiGetTool([], $this->fakeHttp()))->execute(['url' => 'https://api.example.com/x']);

        self::assertStringContainsString('disabled', $result);
    }

    public function testPrivateHostInWhitelistRejectedAtConstruct(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        $this->expectExceptionMessage('allowPrivateHosts');
        new ApiGetTool(['localhost'], $this->fakeHttp());
    }

    public function testPrivateHostAllowedWhenOptIn(): void
    {
        $tool = new ApiGetTool(['localhost'], $this->fakeHttp(200, '{"mcp":"ok"}'), 2048, true);

        $result = $tool->execute(['url' => 'http://localhost:8080/mcp']);

        self::assertStringContainsString('status: 200', $result);
        self::assertStringContainsString('"mcp"', $result);
    }

    // ---------- 执行期防线 ----------

    public function testHostNotInWhitelistRejected(): void
    {
        $tool = new ApiGetTool(['api.example.com'], $this->fakeHttp());

        $result = $tool->execute(['url' => 'https://evil.example.net/steal']);

        self::assertStringContainsString('not in the allowed list', $result);
        self::assertSame([], self::$sent, '拒绝后未发出任何请求');
    }

    public function testInvalidUrlRejected(): void
    {
        $tool = new ApiGetTool(['api.example.com'], $this->fakeHttp());

        self::assertStringContainsString('invalid url', $tool->execute(['url' => 'not-a-url']));
    }

    public function testRedirectsDisabledByDefault(): void
    {
        $tool = new ApiGetTool(['api.example.com'], $this->fakeHttp());

        $tool->execute(['url' => 'https://api.example.com/data']);

        self::assertSame(0, self::$sent[0]['redirects'], '网络族默认 maxRedirects=0(HTTP 特有 SSRF 通道)');
    }

    // ---------- 正常执行(行为不变回归) ----------

    public function testSuccessfulGet(): void
    {
        $tool = new ApiGetTool(['api.example.com'], $this->fakeHttp(200, '{"temp":"26C"}'));

        $result = $tool->execute(['url' => 'https://api.example.com/weather?city=440100']);

        self::assertStringContainsString('status: 200', $result);
        self::assertStringContainsString('"temp"', $result);
        self::assertSame('GET', self::$sent[0]['method']);
        self::assertSame('https://api.example.com/weather?city=440100', self::$sent[0]['url']);
    }

    public function testBodyTruncation(): void
    {
        $tool = new ApiGetTool(['api.example.com'], $this->fakeHttp(200, str_repeat('A', 5000)), 100);

        $result = $tool->execute(['url' => 'https://api.example.com/big']);

        self::assertLessThan(300, strlen($result), 'body 截断生效');
    }
}
