<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\FileCookieStore;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\ScenarioParser;
use Ws\Http\Contract\CookieStoreInterface;
use Ws\Http\Response;

/**
 * design/16 §7 file 模式补缺:FileCookieStore(Netscape 格式)+ Runner 接线。
 *
 * 钉住:collect → persist 往返;构造时文件存在即加载;过期剔除;损坏行容忍;
 * Runner file:<path> 工厂 + run() 结束落盘;memory/none 不受影响。
 */
final class FileCookieStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-cookiestore-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function responseWithSetCookie(string $setCookie): Response
    {
        return new Response(
            ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1],
            '{}',
            sprintf("HTTP/1.1 200 OK\r\nSet-Cookie: %s\r\n", $setCookie)
        );
    }

    // ---------- jar 语义(与 memory 一致) ----------

    public function testCollectAndHeaderForSameHost(): void
    {
        $store = new FileCookieStore($this->dir . '/cookies.txt');
        $store->collectFrom($this->responseWithSetCookie('token=abc; Expires=Wed, 21 Oct 2099 07:28:00 GMT'), 'https://api.example.com/login');

        self::assertSame('token=abc', $store->cookieHeaderFor('https://api.example.com/me'));
        self::assertNull($store->cookieHeaderFor('https://other.example.com/me')); // 主机精确匹配
    }

    public function testSessionCookieExpiresZero(): void
    {
        $store = new FileCookieStore($this->dir . '/cookies.txt');
        $store->collectFrom($this->responseWithSetCookie('sid=xyz'), 'https://api.example.com/'); // 无 Expires = 会话

        self::assertSame('sid=xyz', $store->cookieHeaderFor('https://api.example.com/'));
    }

    public function testExpiredCookiePrunedOnRead(): void
    {
        $store = new FileCookieStore($this->dir . '/cookies.txt');
        $store->collectFrom($this->responseWithSetCookie('old=1; Expires=Wed, 21 Oct 2015 07:28:00 GMT'), 'https://api.example.com/');

        self::assertNull($store->cookieHeaderFor('https://api.example.com/')); // 过期剔除
    }

    // ---------- Netscape 持久化往返 ----------

    public function testPersistRoundTrip(): void
    {
        $path = $this->dir . '/cookies.txt';
        $store = new FileCookieStore($path);
        $store->collectFrom($this->responseWithSetCookie('token=abc; Expires=Wed, 21 Oct 2099 07:28:00 GMT'), 'https://api.example.com/');
        $store->collectFrom($this->responseWithSetCookie('sid=xyz'), 'https://api.example.com/'); // 会话(expires 0)

        $store->persist();

        // 文件为 Netscape 制式(制表符 7 字段 + 注释头)
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertSame('# Netscape HTTP Cookie File', $lines[0]);
        $dataLines = array_values(array_filter($lines, static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
        self::assertCount(2, $dataLines);
        self::assertStringContainsString("\tTRUE\t/\tFALSE\t", $dataLines[0]);

        // 新实例加载 = 状态恢复
        $reloaded = new FileCookieStore($path);
        self::assertSame('token=abc; sid=xyz', $reloaded->cookieHeaderFor('https://api.example.com/'));
    }

    public function testLoadedExpiredEntriesNotResurrected(): void
    {
        $path = $this->dir . '/cookies.txt';
        file_put_contents($path, implode("\n", [
            '# Netscape HTTP Cookie File',
            "api.example.com\tTRUE\t/\tFALSE\t1\tdead\t1", // expiry=1(1970)已过期
            "api.example.com\tTRUE\t/\tFALSE\t0\talive\tok",
        ]));

        $store = new FileCookieStore($path);

        self::assertSame('alive=ok', $store->cookieHeaderFor('https://api.example.com/'));
    }

    public function testCorruptedLinesTolerated(): void
    {
        $path = $this->dir . '/cookies.txt';
        file_put_contents($path, implode("\n", [
            '# comment',
            '',                              // 空行
            'not-a-cookie-line',             // 字段不足
            "api.example.com\tTRUE\t/\tFALSE\t0\tgood\t1",
            "\tTRUE\t/\tFALSE\t0\tnodomain\t2", // 无域名
        ]));

        $store = new FileCookieStore($path);

        self::assertSame('good=1', $store->cookieHeaderFor('https://api.example.com/'));
    }

    // ---------- Runner 接线 ----------

    public function testRunnerFileModePersistsAfterRun(): void
    {
        $cookiePath = $this->dir . '/run-cookies.txt';
        $json = json_encode([
            'id'       => 'cookie-flow',
            'name'     => 'cookie 场景',
            'settings' => ['cookieStore' => 'file:' . $cookiePath],
            'steps'    => [[
                'type'   => 'http',
                'id'     => 's1',
                'name'   => '登录',
                'url'    => 'https://api.example.com/login',
                'method' => 'GET',
            ]],
        ]);

        // 预置:文件含既有 cookie(场景开始加载)
        file_put_contents($cookiePath, "api.example.com\tTRUE\t/\tFALSE\t0\tpre\tparam-value");

        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $factory->queue(new Response(
            ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1],
            '{}',
            "HTTP/1.1 200 OK\r\nSet-Cookie: fresh=brand-new\r\n"
        ));

        $runner = new Runner($factory);
        $runner->run((new ScenarioParser())->parseArray(json_decode((string) $json, true)));

        // run 结束已落盘:既有 + 新收集并存
        $reloaded = new FileCookieStore($cookiePath);
        self::assertSame('pre=param-value; fresh=brand-new', $reloaded->cookieHeaderFor('https://api.example.com/'));
    }

    public function testRunnerMemoryModeUnaffected(): void
    {
        $json = json_encode([
            'id'       => 's',
            'name'     => 'S',
            'steps'    => [[
                'type'   => 'http',
                'id'     => 's1',
                'name'   => 'a',
                'url'    => 'https://api.example.com/',
                'method' => 'GET',
            ]],
        ]);

        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $factory->queue(new Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '{}', "HTTP/1.1 200 OK\r\nSet-Cookie: a=1\r\n"));

        $runner = new Runner($factory);
        $report = $runner->run((new ScenarioParser())->parseArray(json_decode((string) $json, true)));

        self::assertTrue($report->isSuccess()); // memory 默认路径无回归
    }
}
