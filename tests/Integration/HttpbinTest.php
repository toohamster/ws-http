<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ws\Http\Body;
use Ws\Http\Request;
use Ws\Http\RequestException;

/**
 * 真实网络集成测试(httpbin.org)。
 *
 * 默认排除(design/20):phpunit.xml.dist 的 testsuite Unit/Engine 不含本目录;
 * 显式启用:php74 vendor/bin/phpunit --testsuite Integration
 */
final class HttpbinTest extends TestCase
{
    private function request(): Request
    {
        return new Request();
    }

    public function testGetEchoesQuery(): void
    {
        $response = $this->request()->get('https://httpbin.org/get', [], ['name' => 'ahmad', 'tag' => 'a']);

        self::assertSame(200, $response->code);
        self::assertIsObject($response->body);
        self::assertSame('ahmad', $response->body->args->name);
    }

    public function testPostJsonBody(): void
    {
        $response = $this->request()->post('https://httpbin.org/post', [], Body::json(['a' => 1, 'b' => 'x']));

        self::assertSame(200, $response->code);
        self::assertSame('application/json', $response->body->headers->{'Content-Type'});
        self::assertSame('{"a":1,"b":"x"}', $response->body->data);
    }

    public function testPut(): void
    {
        $response = $this->request()->put('https://httpbin.org/put', [], 'raw-put-data');

        self::assertSame(200, $response->code);
        self::assertSame('raw-put-data', $response->body->data);
    }

    public function testDelete(): void
    {
        $response = $this->request()->delete('https://httpbin.org/delete');

        self::assertSame(200, $response->code);
    }

    public function testBasicAuth(): void
    {
        $request = $this->request()->withOptions(static fn ($o) => $o->withAuth('user', 'pass'));

        $response = $request->get('https://httpbin.org/basic-auth/user/pass');

        self::assertSame(200, $response->code);
        self::assertTrue($response->body->authenticated);
        self::assertSame('user', $response->body->user);
    }

    public function testRedirectFollowed(): void
    {
        // httpbin redirect/3 最终落在 /get(3 次重定向被 FOLLOWLOCATION 跟随)
        $response = $this->request()->get('https://httpbin.org/redirect/3');

        self::assertSame(200, $response->code);
        self::assertSame('https://httpbin.org/get', (string) $response->body->url);
        self::assertGreaterThan(0, $response->curlInfo['redirect_count'] ?? -1, '重定向计数 > 0');
    }

    public function test404DoesNotThrow(): void
    {
        $response = $this->request()->get('https://httpbin.org/status/404');

        self::assertSame(404, $response->code);
        self::assertFalse($response->isOk());
    }

    public function testTimeoutThrowsRequestException(): void
    {
        $request = $this->request()->withOptions(static fn ($o) => $o->withTimeoutMs(300));

        $this->expectException(RequestException::class);
        $request->get('https://httpbin.org/delay/5');
    }

    public function testMultipartUpload(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'wshttp');
        file_put_contents($tmpFile, 'file-content-123');

        $response = $this->request()->post('https://httpbin.org/post', [], Body::multipart(['name' => 'x'], ['upload' => $tmpFile]));

        self::assertSame(200, $response->code);
        self::assertSame('file-content-123', $response->body->files->upload ?? null);
    }

    public function testCookieRoundTrip(): void
    {
        $response = $this->request()->get('https://httpbin.org/cookies/set?k=v');

        self::assertSame(200, $response->code);
    }
}
