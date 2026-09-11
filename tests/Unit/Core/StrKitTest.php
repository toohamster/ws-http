<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Ws\Http\StrKit;

/**
 * StrKit::extract({name} 模板提取,design/15 §3.1 template source 的 core 实现)。
 */
final class StrKitTest extends TestCase
{
    public function testExtractBasic(): void
    {
        $result = StrKit::extract('380-250-80-j', '{width}-{height}-{quality}-{format}');

        self::assertSame([
            'width'   => '380',
            'height'  => '250',
            'quality' => '80',
            'format'  => 'j',
        ], $result);
    }

    public function testExtractUrlPath(): void
    {
        $result = StrKit::extract('/2012/08/12/test.html', '/{year}/{month}/{day}/{title}.html');

        self::assertSame([
            'year'  => '2012',
            'month' => '08',
            'day'   => '12',
            'title' => 'test',
        ], $result);
    }

    public function testExtractCaseInsensitive(): void
    {
        $result = StrKit::extract('HELLO World', 'hello {name}', ['case_insensitive' => true]);

        self::assertSame(['name' => 'World'], $result);
    }

    public function testExtractCollapseWhitespace(): void
    {
        $result = StrKit::extract('hello   world', 'hello {name}', ['collapse_whitespace' => true]);

        self::assertSame(['name' => 'world'], $result);
    }

    public function testExtractCustomDelimiters(): void
    {
        $result = StrKit::extract('Hello :name', 'Hello :name', ['delimiters' => [':', '']]);

        self::assertSame(['name' => 'name'], $result);
    }

    public function testExtractStripValues(): void
    {
        $result = StrKit::extract('name:  alice ', 'name: {name}', ['strip_values' => true]);

        self::assertSame(['name' => 'alice'], $result);
    }

    public function testExtractNoMatchReturnsEmpty(): void
    {
        self::assertSame([], StrKit::extract('abc', '{x}-{y}'));
        self::assertSame([], StrKit::extract('', '{x}'));
    }

    public function testExtractInvalidDelimitersReturnsEmpty(): void
    {
        self::assertSame([], StrKit::extract('v', '{x}', ['delimiters' => ['{']]));
        self::assertSame([], StrKit::extract('v', '{x}', ['delimiters' => 'bad']));
    }

    public function testExtractMetaCharsInLiteralParts(): void
    {
        // 字面量段的正则元字符被转义
        $result = StrKit::extract('price=(1.5)$', 'price=({value})$');

        self::assertSame(['value' => '1.5'], $result);
    }

    public function testExtractMultiplePlaceholdersAdjacent(): void
    {
        // 相邻占位符(无字面量锚点):非贪婪语义,首个占位符取最短匹配
        $result = StrKit::extract('abcdef', '{a}{b}');

        self::assertSame(['a' => 'a', 'b' => 'bcdef'], $result);
    }

    public function testExtractPlaceholderNameRules(): void
    {
        // \w+ 占位符名:字母数字下划线
        $result = StrKit::extract('V:2026-09-12', 'V:{date_value}');

        self::assertSame(['date_value' => '2026-09-12'], $result);
    }
}
