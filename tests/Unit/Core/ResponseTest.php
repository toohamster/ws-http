<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\HeaderBag;
use Ws\Http\Response;

/**
 * 设计 11 §3:Response。
 */
final class ResponseTest extends TestCase
{
    /** @return array<string, mixed> 最小 curl_info */
    private function curlInfo(int $code = 200, float $totalTime = 0.5): array
    {
        return ['http_code' => $code, 'total_time' => $totalTime, 'header_size' => 0];
    }

    public function testBasicProperties(): void
    {
        $r = new Response($this->curlInfo(200, 0.42), '{"ok":true}', "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n");

        self::assertSame(200, $r->code);
        self::assertSame('HTTP/1.1 200 OK', $r->statusLine);
        self::assertSame('application/json', $r->headers->get('content-type'));
        self::assertSame('{"ok":true}', $r->rawBody);
        self::assertSame(0.42, $r->curlInfo['total_time']);
    }

    public function testJsonBodyAutoParsed(): void
    {
        $r = new Response($this->curlInfo(), '{"result":0,"list":["a","b"]}', "HTTP/1.1 200 OK\r\nContent-Type: application/json; charset=utf-8\r\n");

        self::assertIsObject($r->body);
        self::assertSame(0, $r->body->result);
        self::assertNull($r->jsonError, '解析成功时 jsonError 为 null');
    }

    public function testJsonBodyAssocMode(): void
    {
        $r = new Response($this->curlInfo(), '{"result":0}', "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n", [true, 512, 0]);

        self::assertIsArray($r->body);
        self::assertSame(0, $r->body['result']);
    }

    public function testBodyStaysFalseForNonJsonContentType(): void
    {
        $r = new Response($this->curlInfo(), '<html></html>', "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n");

        self::assertFalse($r->body);
        self::assertNull($r->jsonError, '非 JSON 内容类型不算解析错误');
    }

    public function testBodyStaysFalseAndRecordsJsonErrorForBrokenJson(): void
    {
        $r = new Response($this->curlInfo(), '{"broken:', "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n");

        self::assertFalse($r->body);
        self::assertNotNull($r->jsonError);
        // 不锁死具体 errno(PHP 内部判定随截断形态而异:语法错/控制字符错等)
        self::assertNotSame(JSON_ERROR_NONE, $r->jsonError[0]);
        self::assertIsString($r->jsonError[1]);
        self::assertNotSame('', $r->jsonError[1]);
    }

    public function testNullLiteralBodyIsValidJson(): void
    {
        // 修复 C7:合法 JSON "null" 不能被误判(json_decode(null 字面量) === null)
        $r = new Response($this->curlInfo(), 'null', "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n");

        self::assertNull($r->body);
        self::assertNull($r->jsonError);
    }

    public function testNumericZeroLiteralBodyIsValidJson(): void
    {
        $r = new Response($this->curlInfo(), '0', "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n");

        self::assertSame(0, $r->body);
        self::assertNull($r->jsonError);
    }

    public function testIsOkBoundaries(): void
    {
        self::assertFalse((new Response($this->curlInfo(199), '', "HTTP/1.1 199 X\r\n"))->isOk());
        self::assertTrue((new Response($this->curlInfo(200), '', "HTTP/1.1 200 OK\r\n"))->isOk());
        self::assertTrue((new Response($this->curlInfo(299), '', "HTTP/1.1 299 X\r\n"))->isOk());
        self::assertFalse((new Response($this->curlInfo(300), '', "HTTP/1.1 300 X\r\n"))->isOk());
        self::assertFalse((new Response($this->curlInfo(404), '', "HTTP/1.1 404 Not Found\r\n"))->isOk());
        self::assertFalse((new Response($this->curlInfo(500), '', "HTTP/1.1 500 Server Error\r\n"))->isOk());
    }

    public function testHeaderProxyIsCaseInsensitive(): void
    {
        $r = new Response($this->curlInfo(), '', "HTTP/1.1 200 OK\r\nX-Request-Id: abc123\r\n");

        self::assertSame('abc123', $r->header('x-request-id'));
        self::assertNull($r->header('X-Missing'));
    }

    public function testTotalTime(): void
    {
        $r = new Response($this->curlInfo(200, 1.25), '', "HTTP/1.1 200 OK\r\n");

        self::assertSame(1.25, $r->totalTime());
    }

    public function testHeadersIsHeaderBag(): void
    {
        $r = new Response($this->curlInfo(), '', "HTTP/1.1 200 OK\r\nServer: nginx\r\n");

        self::assertInstanceOf(HeaderBag::class, $r->headers);
    }
}
