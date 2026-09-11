<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\Exception;
use Ws\Http\RequestException;

/**
 * 设计 11 §7 / design 10 §4:异常体系。
 */
final class ExceptionTest extends TestCase
{
    public function testBaseExceptionDefaults(): void
    {
        $e = new Exception();

        self::assertSame('', $e->getMessage());
        self::assertSame(0, $e->getCode());
    }

    public function testBaseExceptionWithCode(): void
    {
        $e = new Exception('response parse failed', 102);

        self::assertSame('response parse failed', $e->getMessage());
        self::assertSame(102, $e->getCode());
    }

    public function testRequestExceptionCarriesCurlContext(): void
    {
        $e = new RequestException(28, 'Connection timed out after 30001 milliseconds', 'POST', 'http://example.com/api');

        self::assertSame(28, $e->curlErrno());
        self::assertSame('Connection timed out after 30001 milliseconds', $e->curlError());
        self::assertSame('POST', $e->method());
        self::assertSame('http://example.com/api', $e->url());
        // 错误码段:101 = core 传输错误
        self::assertSame(101, $e->getCode());
        // 消息模板: cURL error {errno}: {error} [{method} {url}]
        self::assertSame(
            'cURL error 28: Connection timed out after 30001 milliseconds [POST http://example.com/api]',
            $e->getMessage()
        );
    }

    public function testRequestExceptionIsHttpException(): void
    {
        self::assertInstanceOf(Exception::class, new RequestException(6, 'DNS', 'GET', 'http://x'));
    }
}
