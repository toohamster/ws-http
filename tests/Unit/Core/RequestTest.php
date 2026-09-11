<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\Body;
use Ws\Http\Exception;
use Ws\Http\Request;
use Ws\Http\RequestException;
use Ws\Http\RequestOptions;
use Ws\Http\Response;

/**
 * 设计 11 §1:Request(免网络单测,经 executeCurl 缝隙注入假响应)。
 */
final class RequestTest extends TestCase
{
    /**
     * 造一个可控 Request:executeCurl 返回预设的 [rawResponse, info]。
     *
     * @param array<int, mixed> $capturedOpts 收集 curl_setopt_array 的选项(引用)
     */
    private function fakeRequest(string $rawResponse, array $info = [], ?array &$capturedOpts = null): Request
    {
        return new class($rawResponse, $info, $capturedOpts) extends Request {
            private string $raw;
            private array $info;
            private ?array $sink;

            public function __construct(string $raw, array $info, ?array &$sink)
            {
                parent::__construct();
                $this->raw = $raw;
                $this->info = $info + ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1];
                $this->sink = &$sink;
            }

            protected function executeCurl(array $options): array
            {
                if ($this->sink !== null) {
                    $this->sink = $options;
                }

                return [$this->raw, $this->info];
            }
        };
    }

    // ---------- 快捷方法语义 ----------

    public function testGetBuildsQueryFromArrayParams(): void
    {
        $opts = null;
        $raw = "HTTP/1.1 200 OK\r\n\r\nok";
        $request = $this->fakeRequest($raw, [], $opts);

        $response = $request->get('http://example.com/search', [], ['name' => 'ahmad', 'tag' => 'a']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('http://example.com/search?name=ahmad&tag=a', $opts[CURLOPT_URL]);
    }

    public function testGetFlattensNestedParams(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->get('http://example.com/', [], ['a' => ['b' => 'c']]);

        self::assertSame('http://example.com/?' . http_build_query(['a[b]' => 'c']), $opts[CURLOPT_URL]);
    }

    public function testGetAppendsWithAmpWhenUrlHasQuery(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->get('http://example.com/?x=1', [], ['y' => '2']);

        self::assertSame('http://example.com/?x=1&y=2', $opts[CURLOPT_URL]);
    }

    public function testGetRejectsStringParams(): void
    {
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n");

        $this->expectException(\InvalidArgumentException::class);
        $request->get('http://example.com/', [], 'not-supported');
    }

    public function testPostSendsBodyAndMethod(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->post('http://example.com/api', [], Body::json(['a' => 1]));

        self::assertTrue($opts[CURLOPT_POST] ?? false, 'POST 用 CURLOPT_POST');
        self::assertSame('{"a":1}', $opts[CURLOPT_POSTFIELDS]);
        self::assertStringContainsString('content-type: application/json', implode("\n", $opts[CURLOPT_HTTPHEADER]));
    }

    public function testPutUsesCustomRequest(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->put('http://example.com/api', [], 'data');

        self::assertSame('PUT', $opts[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('data', $opts[CURLOPT_POSTFIELDS]);
        self::assertArrayNotHasKey(CURLOPT_POST, $opts ?? [], '非 POST 不设 CURLOPT_POST');
    }

    public function testSendSupportsCustomMethod(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->send('PROPFIND', 'http://example.com/');

        self::assertSame('PROPFIND', $opts[CURLOPT_CUSTOMREQUEST]);
    }

    // ---------- 头处理 ----------

    public function testDefaultHeadersMergeWithRequestHeaders(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);
        $request = $request->withOptions(static fn (RequestOptions $o) => $o->withDefaultHeader('X-Lib', 'v2'));

        $request->get('http://example.com/', ['Accept' => 'application/json']);

        $headers = implode("\n", $opts[CURLOPT_HTTPHEADER]);
        self::assertStringContainsString('x-lib: v2', $headers);
        self::assertStringContainsString('accept: application/json', $headers);
    }

    public function testUserAgentDefaultAndOverride(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->get('http://example.com/');

        self::assertStringContainsString('user-agent: ws-http/2.0', implode("\n", $opts[CURLOPT_HTTPHEADER]));

        $opts2 = null;
        $request2 = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts2);
        $request2->get('http://example.com/', ['User-Agent' => 'custom/1.0']);

        $headers2 = implode("\n", $opts2[CURLOPT_HTTPHEADER]);
        self::assertStringContainsString('user-agent: custom/1.0', $headers2);
        self::assertSame(1, substr_count($headers2, 'user-agent:'), '不重复追加 UA');
    }

    public function testExpectHeaderDisabledByDefault(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->post('http://example.com/', [], 'data');

        self::assertStringContainsString('expect:', implode("\n", $opts[CURLOPT_HTTPHEADER]));
    }

    // ---------- 默认选项表 ----------

    public function testBaseCurlOptions(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        $request->get('http://example.com/');

        self::assertTrue($opts[CURLOPT_RETURNTRANSFER]);
        self::assertTrue($opts[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(10, $opts[CURLOPT_MAXREDIRS]);
        self::assertTrue($opts[CURLOPT_HEADER]);
        self::assertSame('', $opts[CURLOPT_ENCODING]);
        self::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(30, $opts[CURLOPT_TIMEOUT], '默认超时 30s(修复 B10)');
    }

    public function testUserCurlOptOverridesDefaults(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);
        $request = $request->withOptions(static fn (RequestOptions $o) => $o->withCurlOpt(CURLOPT_TIMEOUT, 5));

        $request->get('http://example.com/');

        self::assertSame(5, $opts[CURLOPT_TIMEOUT], '用户 curlOpt 优先于内置默认');
    }

    public function testSslVerifyPeerFalseDisablesCainfo(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);
        $request = $request->withOptions(static fn (RequestOptions $o) => $o->withVerifyPeer(false));

        $request->get('https://example.com/');

        self::assertFalse($opts[CURLOPT_SSL_VERIFYPEER]);
        self::assertArrayNotHasKey(CURLOPT_CAINFO, $opts, '关闭校验时不设 CAINFO');
    }

    public function testTimeoutMsAppliesNosignalForSubSecond(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);
        $request = $request->withOptions(static fn (RequestOptions $o) => $o->withTimeoutMs(500));

        $request->get('http://example.com/');

        self::assertSame(500, $opts[CURLOPT_TIMEOUT_MS]);
        self::assertSame(1, $opts[CURLOPT_NOSIGNAL], 'ms<1000 自动 NOSIGNAL');
    }

    public function testAuthAndProxyOptions(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);
        $request = $request->withOptions(static fn (RequestOptions $o) => $o
            ->withAuth('u', 'p', CURLAUTH_DIGEST)
            ->withProxy('127.0.0.1', 8787)
            ->withProxyAuth('pu', 'pp'));

        $request->get('http://example.com/');

        self::assertSame(CURLAUTH_DIGEST, $opts[CURLOPT_HTTPAUTH]);
        self::assertSame('u:p', $opts[CURLOPT_USERPWD]);
        self::assertSame('127.0.0.1', $opts[CURLOPT_PROXY]);
        self::assertSame(8787, $opts[CURLOPT_PROXYPORT]);
        self::assertSame('pu:pp', $opts[CURLOPT_PROXYUSERPWD]);
    }

    public function testCookieStringAndCookieFile(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);
        $request = $request->withOptions(static fn (RequestOptions $o) => $o
            ->withCookie('a=1')
            ->withCookieFile('/tmp/ws-http-test-cookie.jar'));

        $request->get('http://example.com/');

        self::assertSame('a=1', $opts[CURLOPT_COOKIE]);
        self::assertSame('/tmp/ws-http-test-cookie.jar', $opts[CURLOPT_COOKIEFILE]);
        self::assertSame('/tmp/ws-http-test-cookie.jar', $opts[CURLOPT_COOKIEJAR]);
    }

    // ---------- 响应切分与错误 ----------

    public function testResponseSplitByHeaderSize(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nX-A: 1\r\n\r\nbody-content";
        $info = ['http_code' => 200, 'header_size' => strlen("HTTP/1.1 200 OK\r\nX-A: 1\r\n\r\n"), 'total_time' => 0.3];
        $request = $this->fakeRequest($raw, $info);

        $response = $request->get('http://example.com/');

        self::assertSame('1', $response->header('x-a'));
        self::assertSame('body-content', $response->rawBody);
        self::assertSame(0.3, $response->totalTime());
    }

    public function testCurlErrorThrowsRequestException(): void
    {
        // executeCurl 抛出传输错误信号的形态:info 中带 errno(设计:S5 约定)
        $request = new class extends Request {
            protected function executeCurl(array $options): array
            {
                throw new RequestException(28, 'Connection timed out after 30001 milliseconds', 'GET', 'http://example.com/');
            }
        };

        try {
            $request->get('http://example.com/');
            self::fail('expected RequestException');
        } catch (RequestException $e) {
            self::assertSame(28, $e->curlErrno());
            self::assertSame('GET', $e->method());
            self::assertSame('http://example.com/', $e->url());
        }
    }

    public function testHttpErrorStatusDoesNotThrow(): void
    {
        // 4xx/5xx 不抛异常,正常返回 Response(设计 11 §7)
        $raw = "HTTP/1.1 404 Not Found\r\n\r\nnope";
        $info = ['http_code' => 404, 'header_size' => strlen("HTTP/1.1 404 Not Found\r\n\r\n"), 'total_time' => 0.1];
        $request = $this->fakeRequest($raw, $info);

        $response = $request->get('http://example.com/missing');

        self::assertSame(404, $response->code);
        self::assertFalse($response->isOk());
    }

    public function testPreparedBodyContentTypeAppliedViaHeaderBag(): void
    {
        $opts = null;
        $request = $this->fakeRequest("HTTP/1.1 200 OK\r\n\r\n", [], $opts);

        // 用户显式头优先于 PreparedBody 建议
        $request->post('http://example.com/', ['Content-Type' => 'application/custom'], Body::json(['a' => 1]));

        $headers = implode("\n", $opts[CURLOPT_HTTPHEADER]);
        self::assertStringContainsString('content-type: application/custom', $headers);
        self::assertSame(1, substr_count($headers, 'content-type:'), '不重复设置 Content-Type');
    }
}
