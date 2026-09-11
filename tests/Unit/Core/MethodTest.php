<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\Method;
use Ws\Http\MethodHelper;

/**
 * 设计 11 §6:Method 常量 + isBodyAllowed()。
 */
final class MethodTest extends TestCase
{
    public function testRfc7231StandardMethods(): void
    {
        self::assertSame('GET', Method::GET);
        self::assertSame('HEAD', Method::HEAD);
        self::assertSame('POST', Method::POST);
        self::assertSame('PUT', Method::PUT);
        self::assertSame('DELETE', Method::DELETE);
        self::assertSame('CONNECT', Method::CONNECT);
        self::assertSame('OPTIONS', Method::OPTIONS);
        self::assertSame('TRACE', Method::TRACE);
        self::assertSame('PATCH', Method::PATCH);
    }

    public function testExtendedMethodsAreRegistered(): void
    {
        self::assertSame('LINK', Method::LINK);
        self::assertSame('PROPFIND', Method::PROPFIND);
        self::assertSame('MKCALENDAR', Method::MKCALENDAR);
        self::assertSame('BIND', Method::BIND);
        self::assertSame('CHECKOUT', Method::CHECKOUT);
    }

    public function testIsBodyAllowed(): void
    {
        // GET/HEAD/CONNECT 不允许体
        self::assertFalse(MethodHelper::isBodyAllowed('GET'));
        self::assertFalse(MethodHelper::isBodyAllowed('HEAD'));
        self::assertFalse(MethodHelper::isBodyAllowed('CONNECT'));

        // 其余允许
        self::assertTrue(MethodHelper::isBodyAllowed('POST'));
        self::assertTrue(MethodHelper::isBodyAllowed('PUT'));
        self::assertTrue(MethodHelper::isBodyAllowed('DELETE'));
        self::assertTrue(MethodHelper::isBodyAllowed('PATCH'));
        self::assertTrue(MethodHelper::isBodyAllowed('OPTIONS'));
        self::assertTrue(MethodHelper::isBodyAllowed('TRACE'));
        self::assertTrue(MethodHelper::isBodyAllowed('PROPFIND'));
        self::assertTrue(MethodHelper::isBodyAllowed('CHECKOUT'));
    }
}
