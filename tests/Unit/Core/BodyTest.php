<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\Body;
use Ws\Http\Exception;

/**
 * 设计 11 §5:Body 请求体构造器(PreparedBody 值对象)。
 */
final class BodyTest extends TestCase
{
    public function testJsonEncodesArray(): void
    {
        $b = Body::json(['name' => 'ahmad', 'company' => 'mashape']);

        self::assertSame('application/json', $b->contentType);
        self::assertSame('{"name":"ahmad","company":"mashape"}', $b->content);
    }

    public function testJsonEncodesObject(): void
    {
        $obj = new \stdClass();
        $obj->a = 1;

        $b = Body::json($obj);

        self::assertSame('{"a":1}', $b->content);
    }

    public function testJsonWithOptions(): void
    {
        $b = Body::json(['url' => 'http://x/a'], JSON_UNESCAPED_SLASHES);

        self::assertSame('{"url":"http://x/a"}', $b->content);
    }

    public function testJsonFailureThrows(): void
    {
        // 递归数据无法编码
        $data = [];
        $data['self'] = &$data;

        try {
            Body::json($data);
            self::fail('expected Exception code 102');
        } catch (Exception $e) {
            self::assertSame(102, $e->getCode());
            self::assertStringContainsStringIgnoringCase('json', $e->getMessage());
        }
    }

    public function testFormEncodesFlatArray(): void
    {
        $b = Body::form(['name' => 'ahmad', 'company' => 'mashape']);

        self::assertSame('application/x-www-form-urlencoded', $b->contentType);
        self::assertSame('name=ahmad&company=mashape', $b->content);
    }

    public function testFormFlattensMultidimensional(): void
    {
        $b = Body::form(['a' => ['b' => 'c']]);

        // http_build_query 标准 RFC1738 行为:[] 编码为 %5B%5D,服务端解码后即 a[b]
        self::assertSame('a%5Bb%5D=c', $b->content);
    }

    public function testFormPassesThroughScalarString(): void
    {
        $b = Body::form('raw=string');

        self::assertSame('raw=string', $b->content);
        self::assertNull($b->contentType, '标量串透传时不建议 Content-Type');
    }

    public function testMultipartMergesFiles(): void
    {
        $b = Body::multipart(
            ['name' => 'ahmad'],
            ['avatar' => __FILE__]
        );

        self::assertSame('multipart/form-data', $b->contentType);
        self::assertIsArray($b->content);
        self::assertSame('ahmad', $b->content['name']);
        self::assertInstanceOf(\CURLFile::class, $b->content['avatar']);
        self::assertSame(__FILE__, $b->content['avatar']->getFilename());
    }

    public function testMultipartKeepsPlainArrays(): void
    {
        $b = Body::multipart(['a' => '1', 'b' => ['c' => '2']]);

        self::assertSame('multipart/form-data', $b->contentType);
        self::assertSame(['a' => '1', 'b' => ['c' => '2']], $b->content);
    }

    public function testFileReturnsCurlFile(): void
    {
        $f = Body::file('/tmp/a.txt', 'text/plain', 'custom.txt');

        self::assertInstanceOf(\CURLFile::class, $f);
        self::assertSame('/tmp/a.txt', $f->getFilename());
        self::assertSame('text/plain', $f->getMimeType());
        self::assertSame('custom.txt', $f->getPostFilename());
    }

    public function testRawBodyWithExplicitContentType(): void
    {
        $b = Body::raw('<xml/>', 'application/xml');

        self::assertSame('<xml/>', $b->content);
        self::assertSame('application/xml', $b->contentType);
    }

    public function testRawBodyWithoutContentType(): void
    {
        $b = Body::raw('plain', null);

        self::assertSame('plain', $b->content);
        self::assertNull($b->contentType);
    }
}
