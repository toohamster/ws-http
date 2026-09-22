<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Tool\ApiFetchTool;
use Ws\Http\NanoGpt\Tool\ApiJsonPostTool;
use Ws\Http\NanoGpt\Tool\ApiTestTool;
use Ws\Http\Request;
use Ws\Http\Response;

/**
 * design/25 C5b:ApiJsonPostTool(body 文件形态)/ ApiTestTool(断言两态)/ ApiFetchTool(提取两态)。
 */
final class ApiToolFamilyTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, body: mixed, headers: array}> */
    public static array $sent = [];

    protected function tearDown(): void
    {
        self::$sent = [];
    }

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
                ApiToolFamilyTest::$sent[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

                return new Response(
                    ['http_code' => $this->code, 'header_size' => 0, 'total_time' => 0.1],
                    $this->body,
                    sprintf("HTTP/1.1 %d X\r\nContent-Type: application/json\r\n\r\n", $this->code)
                );
            }
        };
    }

    // ---------- ApiJsonPostTool ----------

    public function testPostSendsFileContentAsJson(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wsp');
        file_put_contents($file, '{"event":"deploy","id":7}');
        $tool = new ApiJsonPostTool(['api.example.com'], $this->fakeHttp(201));

        $result = $tool->execute(['url' => 'https://api.example.com/hook', 'json_file' => $file]);

        unlink($file);
        self::assertStringContainsString('status: 201', $result);
        self::assertSame('POST', self::$sent[0]['method']);
        self::assertSame('application/json', self::$sent[0]['headers']['Content-Type']);
        self::assertSame('{"event":"deploy","id":7}', (string) self::$sent[0]['body']->content);
    }

    public function testPostRejectsInvalidJsonFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wsp');
        file_put_contents($file, '{not json');
        $tool = new ApiJsonPostTool(['api.example.com'], $this->fakeHttp());

        $result = $tool->execute(['url' => 'https://api.example.com/hook', 'json_file' => $file]);

        unlink($file);
        self::assertStringContainsString('not valid JSON', $result);
        self::assertSame([], self::$sent, '非法 JSON 未发出请求');
    }

    public function testPostMissingFileRejected(): void
    {
        $tool = new ApiJsonPostTool(['api.example.com'], $this->fakeHttp());

        $result = $tool->execute(['url' => 'https://api.example.com/hook', 'json_file' => '/no/such.json']);

        self::assertStringContainsString('cannot read json_file', $result);
    }

    // ---------- ApiTestTool ----------

    public function testTestAllPassed(): void
    {
        $tool = new ApiTestTool(['api.example.com'], $this->fakeHttp(200, '{"data":{"total":42,"city":"广州"}}'));

        $result = $tool->execute([
            'url'              => 'https://api.example.com/x',
            'expect_status'    => 200,
            'expect_json_path' => '$.data.total',
            'expect_op'        => 'gt',
            'expect_value'     => '10',
        ]);

        self::assertStringContainsString('exit: 200', $result);
        self::assertStringContainsString('assert: PASSED (status == 200, actual 200)', $result);
        self::assertStringContainsString('assert: PASSED ($.data.total gt 10, actual 42)', $result);
        self::assertStringNotContainsString('overall: FAILED', $result);
    }

    public function testTestAssertionFailedReportsActual(): void
    {
        $tool = new ApiTestTool(['api.example.com'], $this->fakeHttp(500, '{"error":"boom"}'));

        $result = $tool->execute([
            'url'              => 'https://api.example.com/x',
            'expect_status'    => 200,
            'expect_json_path' => '$.data.total',
            'expect_op'        => 'gt',
            'expect_value'     => '10',
        ]);

        self::assertStringContainsString('assert: FAILED (status != 200, actual 500)', $result);
        self::assertStringContainsString('assert: FAILED ($.data.total gt 10, actual (no match))', $result);
        self::assertStringContainsString('overall: FAILED', $result);
    }

    public function testTestDefaultOpIsNotNull(): void
    {
        $tool = new ApiTestTool(['api.example.com'], $this->fakeHttp(200, '{"ok":true}'));

        $result = $tool->execute(['url' => 'https://api.example.com/x', 'expect_json_path' => '$.ok']);

        self::assertStringContainsString('assert: PASSED ($.ok not_null (none), actual 1)', $result);
    }

    public function testTestNoMatchPathFails(): void
    {
        $tool = new ApiTestTool(['api.example.com'], $this->fakeHttp(200, '{"other":1}'));

        $result = $tool->execute(['url' => 'https://api.example.com/x', 'expect_json_path' => '$.missing', 'expect_op' => 'not_null']);

        self::assertStringContainsString('assert: FAILED ($.missing not_null (none), actual (no match))', $result);
    }

    // ---------- ApiFetchTool ----------

    public function testFetchExtractionResultSet(): void
    {
        $tool = new ApiFetchTool(['api.example.com'], $this->fakeHttp(200, '{"data":{"temp":"26C","city":"广州"},"extra":1}'));

        $result = $tool->execute(['url' => 'https://api.example.com/weather', 'extract' => ['$.data.temp', '$.data.city']]);

        self::assertStringContainsString('status: 200', $result);
        self::assertStringContainsString('"$.data.temp":"26C"', $result);
        self::assertStringContainsString('"$.data.city":"广州"', $result);
        self::assertStringNotContainsString('"extra"', $result, '声明式取数:未声明的字段不返回');
    }

    public function testFetchNoMatchPlaceholder(): void
    {
        $tool = new ApiFetchTool(['api.example.com'], $this->fakeHttp(200, '{"data":{}}'));

        $result = $tool->execute(['url' => 'https://api.example.com/w', 'extract' => ['$.data.missing']]);

        self::assertStringContainsString('"$.data.missing":"(no match)"', $result);
    }

    public function testFetchWithoutExtractFallsBackToRawBody(): void
    {
        $tool = new ApiFetchTool(['api.example.com'], $this->fakeHttp(200, '{"raw":"body"}'));

        $result = $tool->execute(['url' => 'https://api.example.com/plain']);

        self::assertStringContainsString('status: 200', $result);
        self::assertStringContainsString('{"raw":"body"}', $result);
    }

    public function testFetchNonJsonBodyReportsError(): void
    {
        $tool = new ApiFetchTool(['api.example.com'], $this->fakeHttp(200, '<html>not json</html>'));

        $result = $tool->execute(['url' => 'https://api.example.com/html', 'extract' => ['$.x']]);

        self::assertStringContainsString('not valid JSON', $result);
    }
}
