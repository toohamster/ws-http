<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Assert;

use PHPUnit\Framework\TestCase;
use Ws\Http\Assert\Comparison;

/**
 * 设计 12 §2:Comparison 操作符全集(数值化规则 + 每操作符三态)。
 */
final class ComparisonTest extends TestCase
{
    private function assertOp(bool $expected, string $op, $actual, $expectedValue = null, string $note = ''): void
    {
        self::assertSame(
            $expected,
            Comparison::compare($op, $actual, $expectedValue),
            sprintf('%s op=%s actual=%s expected=%s %s', $note, $op, var_export($actual, true), var_export($expectedValue, true), '')
        );
    }

    // ---------- eq:数值化等价类 ----------

    public function testEqNumericNormalization(): void
    {
        // 字符串期望匹配数值字段(兼容 v1 expect 全字符串)
        $this->assertOp(true, 'eq', 200, '200', 'int actual vs string expected');
        $this->assertOp(true, 'eq', '200', 200, 'string actual vs int expected');
        $this->assertOp(true, 'eq', '200', '200', 'both strings numeric');
        $this->assertOp(true, 'eq', 1.5, '1.5', 'float vs string');
        $this->assertOp(true, 'eq', '1.50', '1.5', 'float normalization');
        $this->assertOp(true, 'eq', 0, '0', 'zero');
        $this->assertOp(false, 'eq', 201, '200');
        $this->assertOp(true, 'eq', 'abc', 'abc', 'pure string equality');
        $this->assertOp(false, 'eq', 'abc', 'abd');
        $this->assertOp(false, 'eq', 'abc', '200', 'non-numeric mismatch');
    }

    public function testEqBoolAndNull(): void
    {
        $this->assertOp(true, 'eq', true, true);
        $this->assertOp(false, 'eq', true, 'true', 'bool 不与字符串数值化混淆');
        $this->assertOp(true, 'eq', null, null);
        $this->assertOp(false, 'eq', null, 0);
    }

    public function testEqArraysAndObjects(): void
    {
        $this->assertOp(true, 'eq', ['a' => 1], ['a' => 1], '数组宽松比较');
        $this->assertOp(false, 'eq', ['a' => 1], ['a' => 2]);
    }

    public function testNe(): void
    {
        $this->assertOp(true, 'ne', 201, '200');
        $this->assertOp(false, 'ne', 200, '200');
    }

    // ---------- 数值比较 ----------

    public function testNumericComparisonOps(): void
    {
        $this->assertOp(true, 'gt', 5, '3');
        $this->assertOp(false, 'gt', 3, '3', '相等不算 gt');
        $this->assertOp(true, 'ge', 3, '3');
        $this->assertOp(true, 'ge', 5, '3');
        $this->assertOp(false, 'ge', 2, '3');
        $this->assertOp(true, 'lt', 2, '3');
        $this->assertOp(false, 'lt', 3, '3');
        $this->assertOp(true, 'le', 3, '3');
        $this->assertOp(true, 'le', 2, '3');
        $this->assertOp(false, 'le', 5, '3');
    }

    public function testNumericComparisonRejectsNonNumeric(): void
    {
        $this->expectException(\Ws\Http\Expression\ExpressionException::class);
        $this->expectExceptionCode(203);
        Comparison::compare('gt', 'abc', '3');
    }

    public function testNumericComparisonFloatPrecision(): void
    {
        $this->assertOp(true, 'lt', 0.9, '1', '耗时断言场景');
        $this->assertOp(true, 'le', 1.9999999, '2');
    }

    // ---------- 空值语义 ----------

    public function testNotNullAndIsNull(): void
    {
        $this->assertOp(true, 'not_null', 'x');
        $this->assertOp(true, 'not_null', 0, null, '0 非 null 非空串');
        $this->assertOp(true, 'not_null', false, null, 'false 非 null 非空串');
        $this->assertOp(false, 'not_null', null);
        $this->assertOp(false, 'not_null', '');

        $this->assertOp(true, 'is_null', null);
        $this->assertOp(false, 'is_null', '');
        $this->assertOp(false, 'is_null', 0);
    }

    // ---------- 字符串语义 ----------

    public function testContains(): void
    {
        $this->assertOp(true, 'contains', 'hello world', 'lo wo');
        $this->assertOp(false, 'contains', 'hello', 'xyz');
        $this->assertOp(true, 'contains', ['a', 'b'], 'b', '数组 in_array');
        $this->assertOp(false, 'contains', ['a', 'b'], 'c');
    }

    public function testNotContains(): void
    {
        $this->assertOp(true, 'not_contains', 'hello', 'xyz');
        $this->assertOp(false, 'not_contains', 'hello world', 'lo wo');
    }

    public function testStartsAndEndsWith(): void
    {
        $this->assertOp(true, 'starts_with', 'Bearer xyz', 'Bearer');
        $this->assertOp(false, 'starts_with', 'xyz Bearer', 'Bearer');
        $this->assertOp(true, 'ends_with', 'file.txt', '.txt');
        $this->assertOp(false, 'ends_with', 'file.txt', '.pdf');
    }

    public function testMatches(): void
    {
        $this->assertOp(true, 'matches', '<!doctype html>', '/<!doctype html>.*/i');
        $this->assertOp(false, 'matches', 'nothing here', '/^xyz$/');
        // 无定界符自动包裹
        $this->assertOp(true, 'matches', 'abc123', '^\w+$');
    }

    // ---------- 长度与类型 ----------

    public function testLengthOps(): void
    {
        $this->assertOp(true, 'length_eq', 'abcd', 4);
        $this->assertOp(true, 'length_eq', [1, 2, 3], 3, '数组元素数');
        $this->assertOp(false, 'length_eq', 'ab', 4);
        $this->assertOp(true, 'length_gt', 'abc', 2);
        $this->assertOp(false, 'length_gt', 'ab', 2);
        $this->assertOp(true, 'length_lt', 'a', 2);
        $this->assertOp(false, 'length_lt', 'abc', 2);
    }

    public function testTypeOp(): void
    {
        $this->assertOp(true, 'type', 5, 'integer');
        $this->assertOp(true, 'type', 1.5, 'double');
        $this->assertOp(true, 'type', 'x', 'string');
        $this->assertOp(true, 'type', true, 'boolean');
        $this->assertOp(true, 'type', [], 'array');
        $this->assertOp(true, 'type', null, 'null');
        $this->assertOp(false, 'type', 5, 'string');
    }

    // ---------- 注册表扩展 ----------

    public function testCustomOperatorRegistration(): void
    {
        Comparison::register('is_even', new class implements \Ws\Http\Contract\ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                return is_numeric($actual) && ((int) $actual) % 2 === 0;
            }
        });

        $this->assertOp(true, 'is_even', 4, null, '自定义操作符经注册生效');
        $this->assertOp(false, 'is_even', 3, null);
    }

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Comparison::compare('no_such_op', 1, 1);
    }
}
