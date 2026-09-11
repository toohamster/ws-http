<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\UrlKit;

/**
 * 设计 11 §1.2:buildHttpQuery / encodeUrl 纯函数。
 */
final class UrlKitTest extends TestCase
{
    // ---------- buildHttpQuery ----------

    public function testBuildHttpQueryFlattensNestedArrays(): void
    {
        $result = UrlKit::buildHttpQuery(['a' => ['b' => 'c', 'd' => 'e'], 'f' => 'g']);

        self::assertSame(['a[b]' => 'c', 'a[d]' => 'e', 'f' => 'g'], $result);
    }

    public function testBuildHttpQueryDeepNesting(): void
    {
        $result = UrlKit::buildHttpQuery(['x' => ['y' => ['z' => '1']]]);

        self::assertSame(['x[y][z]' => '1'], $result);
    }

    public function testBuildHttpQueryAcceptsObjects(): void
    {
        $obj = new \stdClass();
        $obj->a = '1';
        $nested = new \stdClass();
        $nested->b = '2';
        $obj->nested = $nested;

        $result = UrlKit::buildHttpQuery($obj);

        self::assertSame(['a' => '1', 'nested[b]' => '2'], $result);
    }

    public function testBuildHttpQueryPreservesCurlFile(): void
    {
        $file = new \CURLFile('/tmp/f.txt');
        $result = UrlKit::buildHttpQuery(['upload' => $file, 'name' => 'x']);

        self::assertSame($file, $result['upload']);
        self::assertSame('x', $result['name']);
    }

    public function testBuildHttpQueryScalars(): void
    {
        $result = UrlKit::buildHttpQuery(['i' => 1, 'f' => 1.5, 'b' => true, 'n' => null]);

        self::assertSame(['i' => 1, 'f' => 1.5, 'b' => true, 'n' => null], $result);
    }

    // ---------- encodeUrl ----------

    public function testEncodeUrlPassesThroughSimpleUrl(): void
    {
        self::assertSame('http://example.com/path', UrlKit::encodeUrl('http://example.com/path'));
    }

    public function testEncodeUrlRecodesChineseQuery(): void
    {
        $encoded = UrlKit::encodeUrl('http://example.com/search?name=张三&city=440100');

        self::assertStringStartsWith('http://example.com/search?', $encoded);
        self::assertStringContainsString('city=440100', $encoded);
        self::assertStringNotContainsString('张三', $encoded, '中文值必须被编码');
        self::assertStringContainsString(urlencode('张三'), $encoded);
    }

    public function testEncodeUrlKeepsEncodedQueryAsIs(): void
    {
        // 已编码的 query(纯 ASCII)原样保留,不做 decode/encode 往返
        $url = 'http://example.com/search?name=%E5%BC%A0%E4%B8%89&city=440100';

        self::assertSame($url, UrlKit::encodeUrl($url));
    }

    public function testEncodeUrlWithPort(): void
    {
        self::assertSame('http://example.com:8080/', UrlKit::encodeUrl('http://example.com:8080/'));
    }

    public function testEncodeUrlPreservesFragment(): void
    {
        self::assertSame('http://example.com/page#a', UrlKit::encodeUrl('http://example.com/page#a'));
    }

    public function testEncodeUrlRejectsMissingScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UrlKit::encodeUrl('example.com/no-scheme');
    }

    public function testEncodeUrlRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UrlKit::encodeUrl('');
    }
}
