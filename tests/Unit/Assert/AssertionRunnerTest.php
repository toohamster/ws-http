<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Assert;

use PHPUnit\Framework\TestCase;
use Ws\Http\Assert\AssertionRunner;
use Ws\Http\Assert\Watcher;
use Ws\Http\Assert\AssertionException;
use Ws\Http\Response;

/**
 * 设计 12 §3–§5:Assertion 提取、Watcher 流式、AssertionRunner 收集式。
 */
final class AssertionRunnerTest extends TestCase
{
    private function response(string $rawBody = '{"result":0,"total":2}', int $code = 200, array $info = []): Response
    {
        $info += ['http_code' => $code, 'header_size' => 0, 'total_time' => 0.42];
        $headers = $code === 200 ? "HTTP/1.1 200 OK\r\nServer: nginx\r\nContent-Type: application/json\r\n" : "HTTP/1.1 {$code} X\r\n";

        return new Response($info, $rawBody, $headers);
    }

    // ---------- AssertionRunner:fromArray + 收集式 ----------

    public function testRunnerCollectsAllFailuresWithoutShortCircuit(): void
    {
        $response = $this->response();
        $assertions = AssertionRunner::fromArray([
            ['source' => 'status', 'op' => 'eq', 'expected' => 200],        // pass
            ['source' => 'json', 'path' => '$.result', 'op' => 'eq', 'expected' => 0],  // pass
            ['source' => 'json', 'path' => '$.total', 'op' => 'gt', 'expected' => 5],   // fail
            ['source' => 'header', 'path' => 'X-Missing', 'op' => 'not_null', 'expected' => null], // fail
        ]);

        $results = (new AssertionRunner())->run($response, $assertions);

        self::assertCount(4, $results, '不短路,全部执行');
        self::assertTrue($results[0]->passed);
        self::assertTrue($results[1]->passed);
        self::assertFalse($results[2]->passed);
        self::assertFalse($results[3]->passed);
        self::assertStringContainsString('$.total', $results[2]->message);
        self::assertStringContainsString('X-Missing', $results[3]->message);
    }

    public function testRunnerCarriesActualValueOnFailure(): void
    {
        $assertions = AssertionRunner::fromArray([
            ['source' => 'json', 'path' => '$.total', 'op' => 'eq', 'expected' => 99],
        ]);

        $results = (new AssertionRunner())->run($this->response(), $assertions);

        self::assertFalse($results[0]->passed);
        self::assertSame(2, $results[0]->actual);
    }

    public function testRunnerJsonPathNoMatchIsFailure(): void
    {
        $assertions = AssertionRunner::fromArray([
            ['source' => 'json', 'path' => '$.missing', 'op' => 'eq', 'expected' => 1],
        ]);

        $results = (new AssertionRunner())->run($this->response(), $assertions);

        self::assertFalse($results[0]->passed);
        self::assertStringContainsString('$.missing', $results[0]->message);
    }

    public function testRunnerStatusAndTimeSources(): void
    {
        $assertions = AssertionRunner::fromArray([
            ['source' => 'status', 'op' => 'eq', 'expected' => '200'],
            ['source' => 'time', 'op' => 'lt', 'expected' => 1],
            ['source' => 'raw_body', 'op' => 'contains', 'expected' => '"result":0'],
        ]);

        $results = (new AssertionRunner())->run($this->response(), $assertions);

        self::assertTrue($results[0]->passed, 'status 200 vs "200" 数值化');
        self::assertTrue($results[1]->passed, 'time 0.42 < 1');
        self::assertTrue($results[2]->passed, 'raw_body contains');
    }

    public function testRunnerHeaderCaseInsensitive(): void
    {
        $assertions = AssertionRunner::fromArray([
            ['source' => 'header', 'path' => 'SERVER', 'op' => 'eq', 'expected' => 'nginx'],
        ]);

        $results = (new AssertionRunner())->run($this->response(), $assertions);

        self::assertTrue($results[0]->passed, '头名大小写不敏感(修复 B8)');
    }

    public function testRunnerSupportsName(): void
    {
        $assertions = AssertionRunner::fromArray([
            ['source' => 'status', 'op' => 'eq', 'expected' => 500, 'name' => '登录后状态必须失败'],
        ]);

        $results = (new AssertionRunner())->run($this->response(), $assertions);

        self::assertStringContainsString('登录后状态必须失败', $results[0]->message);
    }

    // ---------- Watcher:流式(失败即抛) ----------

    public function testWatcherChainAllPass(): void
    {
        Watcher::create($this->response())
            ->assertStatusCode(200)
            ->assertTotalTimeLessThan(1)
            ->assertHeadersExist(['Server', 'Content-Type'])
            ->assertHeaders(['Server' => 'nginx'])
            ->assertBody('IS_VALID_JSON')
            ->assertJsonPath('$.result', 'eq', 0);
        // 无异常即通过
        $this->addToAssertionCount(1);
    }

    public function testWatcherStatusCodeFailure(): void
    {
        $this->expectException(AssertionException::class);
        $this->expectExceptionCode(301);
        Watcher::create($this->response())->assertStatusCode(500);
    }

    public function testWatcherTotalTimeFailure(): void
    {
        $this->expectException(AssertionException::class);
        $this->expectExceptionCode(305);
        Watcher::create($this->response())->assertTotalTimeLessThan(0.1);
    }

    public function testWatcherHeadersExistFailure(): void
    {
        $this->expectException(AssertionException::class);
        $this->expectExceptionCode(302);
        Watcher::create($this->response())->assertHeadersExist(['X-Nope']);
    }

    public function testWatcherHeadersValueFailure(): void
    {
        $this->expectException(AssertionException::class);
        $this->expectExceptionCode(303);
        Watcher::create($this->response())->assertHeaders(['Server' => 'apache']);
    }

    public function testWatcherHeadersIndexArrayDegradesToExist(): void
    {
        Watcher::create($this->response())->assertHeaders(['Server', 'Content-Type']);
        $this->addToAssertionCount(1);
    }

    // ---------- assertBody 语义(修复 B1) ----------

    public function testWatcherAssertBodyContains(): void
    {
        // 包含匹配:strpos(actual, expected) !== false(修复 B1 参数顺序)
        Watcher::create($this->response('hello world'))->assertBody('lo wo');
        $this->addToAssertionCount(1);
    }

    public function testWatcherAssertBodyContainsFailure(): void
    {
        // 修复后的行为:体包含期望才通过;'hello' 不包含 'world'
        $this->expectException(AssertionException::class);
        $this->expectExceptionCode(304);
        Watcher::create($this->response('hello'))->assertBody('world');
    }

    public function testWatcherAssertBodyIsEmpty(): void
    {
        Watcher::create($this->response(''))->assertBody('IS_EMPTY');
        $this->addToAssertionCount(1);
    }

    public function testWatcherAssertBodyIsEmptyFailure(): void
    {
        $this->expectException(AssertionException::class);
        Watcher::create($this->response('x'))->assertBody('IS_EMPTY');
    }

    public function testWatcherAssertBodyRegex(): void
    {
        Watcher::create($this->response('<!doctype html><html>'))->assertBody('/<!doctype html>.*/', true);
        $this->addToAssertionCount(1);
    }

    public function testWatcherAssertBodyIsValidJson(): void
    {
        // C7:合法 "null"/"0"/'"s"' 都是有效 JSON
        Watcher::create($this->response('null'))->assertBody('IS_VALID_JSON');
        Watcher::create($this->response('0'))->assertBody('IS_VALID_JSON');
        Watcher::create($this->response('"s"'))->assertBody('IS_VALID_JSON');
        $this->addToAssertionCount(1);
    }

    public function testWatcherAssertBodyIsValidJsonFailure(): void
    {
        $this->expectException(AssertionException::class);
        Watcher::create($this->response('{broken'))->assertBody('IS_VALID_JSON');
    }

    // ---------- assertBodyJson 系列 ----------

    public function testWatcherAssertBodyJsonLoose(): void
    {
        $expected = ['result' => 0, 'total' => 2];

        Watcher::create($this->response())->assertBodyJson($expected);
        $this->addToAssertionCount(1);
    }

    public function testWatcherAssertBodyJsonFailureIncludesDetail(): void
    {
        try {
            Watcher::create($this->response())->assertBodyJson(['result' => 99], true);
            self::fail('expected AssertionException');
        } catch (AssertionException $e) {
            self::assertSame(305, $e->getCode());
            self::assertStringContainsString('result', $e->getMessage());
        }
    }

    public function testWatcherAssertBodyJsonFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wshttp');
        file_put_contents($file, '{"result":0,"total":2}');

        Watcher::create($this->response())->assertBodyJsonFile($file);
        $this->addToAssertionCount(1);
    }

    public function testWatcherAssertBodyJsonFileMissingFileThrows(): void
    {
        $this->expectException(AssertionException::class);
        Watcher::create($this->response())->assertBodyJsonFile('/nonexistent/x.json');
    }

    public function testWatcherAssertJsonPathOperator(): void
    {
        Watcher::create($this->response())
            ->assertJsonPath('$.total', 'gt', 1)
            ->assertJsonPath('$.result', 'ne', 5);
        $this->addToAssertionCount(1);
    }

    // ---------- 失败消息模板(design/12 §4.2) ----------

    public function testFailureMessageTemplate(): void
    {
        try {
            Watcher::create($this->response())->assertJsonPath('$.total', 'gt', 99);
            self::fail('expected AssertionException');
        } catch (AssertionException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('json', $msg);
            self::assertStringContainsString('$.total', $msg);
            self::assertStringContainsString('gt', $msg);
            self::assertStringContainsString('99', $msg);
            self::assertStringContainsString('2', $msg, '含实际值');
        }
    }

    public function testWatcherCollectModeReturnsResults(): void
    {
        // collect() 期间失败不抛(设计 12 §4 collect)
        $watcher = Watcher::create($this->response());

        $results = $watcher->collect(static function (Watcher $w): void {
            $w->assertStatusCode(200);
            $w->assertStatusCode(500);
        });

        self::assertCount(2, $results);
        self::assertTrue($results[0]->passed);
        self::assertFalse($results[1]->passed);
    }

    public function testWatcherCollectModeStopsThrowingAfterExit(): void
    {
        // collect 退出后恢复抛出语义
        $watcher = Watcher::create($this->response());
        $watcher->collect(static function (Watcher $w): void {
            $w->assertStatusCode(500); // 失败被收集
        });

        $this->expectException(AssertionException::class);
        $watcher->assertStatusCode(500); // 恢复抛出
    }
}
