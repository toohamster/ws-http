<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

/**
 * 闭包包装比较器(S7.5 Extension 机制):用户的现成函数一行接入注册表。
 *
 * 用法(本地函数或远程 API 封装均可):
 *   Comparison::register('decrypt_eq', new FnComparator(fn($a, $e) => decrypt($a) === $e));
 *
 * 约定:函数内部发起 HTTP 调用合法,但超时/失败语义自担(返回 false 即断言失败,引擎不重试)。
 */
final class FnComparator implements ComparatorInterface
{
    /** @var \Closure(mixed, mixed): bool */
    private $fn;

    /**
     * @param \Closure(mixed, mixed): bool $fn
     */
    public function __construct(\Closure $fn)
    {
        $this->fn = $fn;
    }

    /**
     * @param mixed $actual
     * @param mixed $expected
     */
    public function compare($actual, $expected): bool
    {
        return ($this->fn)($actual, $expected);
    }
}
