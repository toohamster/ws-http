<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Ws\Http\Assert\Assertion;
use Ws\Http\Assert\AssertionResult;
use Ws\Http\Support\ResultSet;

/**
 * S7.5:ResultSet 通用结果容器(断言/提取/步骤/报告的统一消费形态)。
 */
final class ResultSetTest extends TestCase
{
    private function result(bool $passed): AssertionResult
    {
        return new AssertionResult(new Assertion('status', '', 'eq', 200), $passed, $passed ? null : 'fail', 200);
    }

    public function testAddAndAllPreserveOrder(): void
    {
        $set = new ResultSet();
        $set->add($this->result(true));
        $set->add($this->result(false));

        self::assertCount(2, $set);
        self::assertSame(2, $set->count(), "同一实例集合:按数量与顺序断言");
    }

    public function testConstructFromArray(): void
    {
        $set = new ResultSet([$this->result(true), $this->result(false)]);

        self::assertCount(2, $set);
        self::assertSame(2, $set->count(), "同一实例集合:按数量与顺序断言");
    }

    public function testPassedAndFailedFilters(): void
    {
        $set = new ResultSet([$this->result(true), $this->result(false), $this->result(true)]);

        self::assertCount(2, $set->passed());
        self::assertCount(1, $set->failed());
        self::assertCount(2, $set->passed()->all());
    }

    public function testFilterReturnsNewSet(): void
    {
        $set = new ResultSet([$this->result(true), $this->result(false)]);

        $filtered = $set->filter(static fn (object $r) => $r->passed);

        self::assertCount(1, $filtered);
        self::assertCount(2, $set, '原集合不变');
        self::assertNotSame($set, $filtered);
    }

    public function testIsEmpty(): void
    {
        self::assertTrue((new ResultSet())->isEmpty());
        self::assertFalse((new ResultSet([$this->result(true)]))->isEmpty());
    }

    public function testToArrayDelegatesToItemToArray(): void
    {
        $item = new class {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };

        self::assertSame([['key' => 'value']], (new ResultSet([$item]))->toArray());
    }

    public function testToArrayThrowsForNonConvertibleItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ResultSet([new \stdClass()]))->toArray();
    }

    public function testIterableAndCountable(): void
    {
        $set = new ResultSet([$this->result(true), $this->result(false)]);

        $count = 0;
        foreach ($set as $item) {
            $count++;
        }

        self::assertSame(2, $count);
        self::assertCount(2, $set);
    }
}
