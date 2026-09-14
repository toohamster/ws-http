<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Assert;

use PHPUnit\Framework\TestCase;
use Ws\Http\Assert\Comparison;
use Ws\Http\Contract\FnComparator;

/**
 * S7.5:Extension 机制 —— 闭包包装器让用户函数一行接入。
 */
final class FnWrappersTest extends TestCase
{
    protected function setUp(): void
    {
        Comparison::resetForTest();
    }

    public function testFnComparatorLocalFunction(): void
    {
        // 用户的本地校验函数(如加解密)包装注册
        Comparison::register('decrypt_eq', new FnComparator(
            static fn ($actual, $expected): bool => strrev((string) $actual) === (string) $expected
        ));

        self::assertTrue(Comparison::compare('decrypt_eq', 'abc', 'cba'));
        self::assertFalse(Comparison::compare('decrypt_eq', 'abc', 'abc'));
    }

    public function testFnComparatorSimulatingRemoteCheck(): void
    {
        // 远程 API 校验形态:函数内部自行处理失败语义(返回 false 即断言失败)
        Comparison::register('remote_check', new FnComparator(
            static function ($actual, $expected): bool {
                // 模拟远程调用失败回退 false(引擎不做重试,设计约定)
                return $actual === $expected;
            }
        ));

        self::assertTrue(Comparison::compare('remote_check', 'token-x', 'token-x'));
        self::assertFalse(Comparison::compare('remote_check', 'token-x', 'token-y'));
    }

    public function testCustomOperatorSurvivesAcrossUses(): void
    {
        Comparison::register('always_yes', new FnComparator(static fn (): bool => true));

        self::assertTrue(Comparison::compare('always_yes', null, null));
        self::assertTrue(Comparison::compare('always_yes', 'a', 1));
    }
}
