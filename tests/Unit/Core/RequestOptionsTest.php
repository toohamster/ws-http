<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\HeaderBag;
use Ws\Http\RequestOptions;

/**
 * 设计 11 §4:RequestOptions 配置值对象(wither 不可变 + 校验 + 读取器)。
 */
final class RequestOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $o = new RequestOptions();

        // 内置默认超时 30s(修复 B10)
        self::assertSame(30, $o->timeout());
        self::assertNull($o->timeoutMs());
        self::assertTrue($o->verifyPeer());
        self::assertTrue($o->verifyHost());
        self::assertNull($o->caBundle(), '默认用系统 CA(不再内置证书包)');
        self::assertSame([false, 512, 0], $o->jsonOpts());
        self::assertCount(0, $o->defaultHeaders());
        self::assertNull($o->cookie());
        self::assertNull($o->cookieFile());
        self::assertNull($o->auth());
        self::assertNull($o->proxy());
        self::assertSame([], $o->curlOpts());
        self::assertSame(10, $o->maxRedirects());
    }

    public function testWitherIsImmutable(): void
    {
        $original = new RequestOptions();
        $modified = $original->withTimeout(5);

        self::assertSame(30, $original->timeout(), '原实例不变');
        self::assertSame(5, $modified->timeout());
        self::assertNotSame($original, $modified);
    }

    public function testWithTimeoutRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RequestOptions())->withTimeout(0);
    }

    public function testWithTimeoutRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RequestOptions())->withTimeout(-1);
    }

    public function testWithTimeoutMsRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RequestOptions())->withTimeoutMs(0);
    }

    public function testWithTimeoutMs(): void
    {
        $o = (new RequestOptions())->withTimeoutMs(500);

        self::assertSame(500, $o->timeoutMs());
        // timeoutMs 与 timeout 互斥:设置 ms 后保留 timeout 供回退,但 send 只用 ms(设计 11 §1.6)
        self::assertSame(30, $o->timeout());
    }

    public function testWithVerifyPeerAndHost(): void
    {
        $o = (new RequestOptions())
            ->withVerifyPeer(false)
            ->withVerifyHost(false);

        self::assertFalse($o->verifyPeer());
        self::assertFalse($o->verifyHost());
    }

    public function testWithCaBundle(): void
    {
        $o = (new RequestOptions())->withCaBundle('/path/to/ca.pem');

        self::assertSame('/path/to/ca.pem', $o->caBundle());
    }

    public function testWithJsonOpts(): void
    {
        $o = (new RequestOptions())->withJsonOpts(true, 256, JSON_BIGINT_AS_STRING);

        self::assertSame([true, 256, JSON_BIGINT_AS_STRING], $o->jsonOpts());
    }

    public function testDefaultHeadersMergeAndClear(): void
    {
        $o = (new RequestOptions())
            ->withDefaultHeader('X-A', '1')
            ->withDefaultHeaders(['X-B' => '2', 'X-C' => '3']);
        // withDefaultHeaders 批量合并(不覆盖已存在的 X-B?设计 11 §4:批量"合并")
        $o2 = $o->withDefaultHeaders(['X-B' => 'override']);

        self::assertSame('1', $o2->defaultHeaders()->get('x-a'));
        self::assertSame('3', $o2->defaultHeaders()->get('x-c'));
        self::assertSame('override', $o2->defaultHeaders()->get('x-b'), '批量合并覆盖同名');

        $o3 = $o2->withoutDefaultHeaders();
        self::assertCount(0, $o3->defaultHeaders());
    }

    public function testDefaultHeadersReturnsHeaderBag(): void
    {
        $o = (new RequestOptions())->withDefaultHeader('Accept', 'application/json');

        self::assertInstanceOf(HeaderBag::class, $o->defaultHeaders());
        self::assertSame('application/json', $o->defaultHeaders()->get('accept'));
    }

    public function testCookieAndCookieFile(): void
    {
        $o = (new RequestOptions())
            ->withCookie('a=1; b=2')
            ->withCookieFile('/tmp/cookies.txt');

        self::assertSame('a=1; b=2', $o->cookie());
        self::assertSame('/tmp/cookies.txt', $o->cookieFile());
    }

    public function testAuth(): void
    {
        $o = (new RequestOptions())->withAuth('user', 'pass');

        self::assertSame(['user' => 'user', 'pass' => 'pass', 'method' => CURLAUTH_BASIC], $o->auth());

        $o2 = (new RequestOptions())->withAuth('u', 'p', CURLAUTH_DIGEST);

        self::assertSame(CURLAUTH_DIGEST, $o2->auth()['method']);
    }

    public function testProxy(): void
    {
        $o = (new RequestOptions())->withProxy('127.0.0.1', 8787);

        self::assertSame([
            'address' => '127.0.0.1',
            'port'    => 8787,
            'type'    => CURLPROXY_HTTP,
            'tunnel'  => false,
            'auth'    => null,
        ], $o->proxy());

        $o2 = $o->withProxy('10.0.0.1', 1080, CURLPROXY_SOCKS5, true)
            ->withProxyAuth('u', 'p', CURLAUTH_DIGEST);

        self::assertSame(CURLPROXY_SOCKS5, $o2->proxy()['type']);
        self::assertTrue($o2->proxy()['tunnel']);
        self::assertSame(
            ['user' => 'u', 'pass' => 'p', 'method' => CURLAUTH_DIGEST],
            $o2->proxy()['auth']
        );
    }

    public function testProxyRejectsInvalidPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RequestOptions())->withProxy('127.0.0.1', 0);
    }

    public function testProxyRejectsOutOfRangePort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RequestOptions())->withProxy('127.0.0.1', 70000);
    }

    public function testCurlOpts(): void
    {
        $o = (new RequestOptions())
            ->withCurlOpt(CURLOPT_COOKIE, 'a=1')
            ->withCurlOpt(CURLOPT_REFERER, 'http://x')
            ->withCurlOpts([CURLOPT_TIMEOUT => 10, CURLOPT_COOKIE => 'b=2']);

        $opts = $o->curlOpts();

        self::assertSame('b=2', $opts[CURLOPT_COOKIE], '后设置覆盖前值');
        self::assertSame('http://x', $opts[CURLOPT_REFERER]);
        self::assertSame(10, $opts[CURLOPT_TIMEOUT]);

        $o2 = $o->withoutCurlOpts();
        self::assertSame([], $o2->curlOpts());
    }

    public function testMaxRedirects(): void
    {
        $o = (new RequestOptions())->withMaxRedirects(3);

        self::assertSame(3, $o->maxRedirects());
    }

    public function testMaxRedirectsRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RequestOptions())->withMaxRedirects(-1);
    }
}
