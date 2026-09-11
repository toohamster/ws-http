<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\HeaderBag;

/**
 * 设计 11 §2:HeaderBag 全量行为。
 */
final class HeaderBagTest extends TestCase
{
    public function testSetAndGetAreCaseInsensitive(): void
    {
        $bag = new HeaderBag();
        $bag->set('Content-Type', 'application/json');

        self::assertTrue($bag->has('content-type'));
        self::assertTrue($bag->has('CONTENT-TYPE'));
        self::assertSame('application/json', $bag->get('Content-Type'));
    }

    public function testSetReplacesPreviousValue(): void
    {
        $bag = new HeaderBag(['Accept' => 'text/html']);
        $bag->set('accept', 'application/json');

        self::assertSame(['application/json'], $bag->all('Accept'));
        self::assertSame('application/json', $bag->get('accept'));
    }

    public function testAddAccumulatesMultipleValues(): void
    {
        $bag = new HeaderBag();
        $bag->add('Set-Cookie', 'a=1; Path=/');
        $bag->add('set-cookie', 'b=2; Path=/');
        $bag->add('SET-COOKIE', 'c=3; Path=/');

        self::assertSame(['a=1; Path=/', 'b=2; Path=/', 'c=3; Path=/'], $bag->all('Set-Cookie'));
        // get() 返回逗号拼接
        self::assertSame('a=1; Path=/, b=2; Path=/, c=3; Path=/', $bag->get('Set-Cookie'));
    }

    public function testGetMissingHeaderReturnsNull(): void
    {
        $bag = new HeaderBag();

        self::assertNull($bag->get('X-Missing'));
        self::assertFalse($bag->has('X-Missing'));
        self::assertSame([], $bag->all('X-Missing'));
    }

    public function testRemoveIsCaseInsensitive(): void
    {
        $bag = new HeaderBag(['X-Token' => 'secret', 'Accept' => '*/*']);
        $bag->remove('x-token');

        self::assertFalse($bag->has('X-Token'));
        self::assertTrue($bag->has('Accept'));
    }

    public function testNamesPreserveOriginalCasing(): void
    {
        $bag = new HeaderBag(['Content-Type' => 'application/json']);
        $bag->add('X-Request-Id', 'abc');

        self::assertSame(['Content-Type', 'X-Request-Id'], $bag->names());
    }

    public function testToArrayPreservesOriginalCasingAndValues(): void
    {
        $bag = new HeaderBag(['Content-Type' => 'application/json']);
        // 首次出现的大小写被保留(设计 11 §2 行为 1)
        $bag->add('Set-Cookie', 'a=1');
        $bag->add('set-cookie', 'b=2');

        self::assertSame([
            'Content-Type' => 'application/json',
            'Set-Cookie'   => ['a=1', 'b=2'],
        ], $bag->toArray());
    }

    public function testEmptyStringValueIsPreserved(): void
    {
        $bag = new HeaderBag(['Expect' => '']);

        self::assertTrue($bag->has('expect'));
        self::assertSame('', $bag->get('Expect'));
    }

    public function testCountableAndIterable(): void
    {
        $bag = new HeaderBag(['A' => '1', 'B' => '2']);

        self::assertCount(2, $bag);
        $names = [];
        foreach ($bag as $name => $value) {
            $names[] = $name;
        }
        self::assertSame(['A', 'B'], $names);
    }

    // ---------- fromRawHeaders ----------

    public function testFromRawHeadersParsesBasicResponse(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 27\r\n";

        $bag = HeaderBag::fromRawHeaders($raw);

        self::assertSame('application/json', $bag->get('content-type'));
        self::assertSame('27', $bag->get('Content-Length'));
        self::assertFalse($bag->has('0'), 'status line must not become a header');
    }

    public function testFromRawHeadersMergesDuplicateNames(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nSet-Cookie: a=1; Path=/\r\nSet-Cookie: b=2; Path=/\r\n";

        $bag = HeaderBag::fromRawHeaders($raw);

        self::assertSame(['a=1; Path=/', 'b=2; Path=/'], $bag->all('Set-Cookie'));
    }

    public function testFromRawHeadersHandlesFoldedLines(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nX-Long: first part\r\n\tcontinued part\r\nX-Other: v\r\n";

        $bag = HeaderBag::fromRawHeaders($raw);

        self::assertStringContainsString('first part', (string) $bag->get('X-Long'));
        self::assertStringContainsString('continued part', (string) $bag->get('X-Long'));
        self::assertSame('v', $bag->get('x-other'));
    }

    public function testFromRawHeadersHandlesEmptyValueHeader(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nExpect:\r\nServer: nginx\r\n";

        $bag = HeaderBag::fromRawHeaders($raw);

        self::assertTrue($bag->has('expect'));
        self::assertSame('', $bag->get('expect'));
    }

    public function testFromRawHeadersAcceptsLfOnly(): void
    {
        $raw = "HTTP/1.1 404 Not Found\nServer: nginx\n";

        $bag = HeaderBag::fromRawHeaders($raw);

        self::assertSame('nginx', $bag->get('server'));
    }

    public function testFromRawHeadersValuesAreTrimmed(): void
    {
        $bag = HeaderBag::fromRawHeaders("HTTP/1.1 200 OK\r\nX-Space:   padded value  \r\n");

        self::assertSame('padded value', $bag->get('x-space'));
    }

    // ---------- toCurlHeaders ----------

    public function testToCurlHeadersOutputsLowercaseNames(): void
    {
        $bag = new HeaderBag(['Content-Type' => 'application/json', 'Accept' => '*/*']);

        self::assertSame(
            ['content-type: application/json', 'accept: */*'],
            $bag->toCurlHeaders()
        );
    }

    public function testToCurlHeadersJoinsMultipleValues(): void
    {
        $bag = new HeaderBag();
        $bag->add('Cookie', 'a=1');
        $bag->add('Cookie', 'b=2');

        self::assertSame(['cookie: a=1, b=2'], $bag->toCurlHeaders());
    }

    public function testToCurlHeadersPreservesEmptyValue(): void
    {
        $bag = new HeaderBag(['Expect' => '']);

        self::assertSame(['expect: '], $bag->toCurlHeaders());
    }
}
