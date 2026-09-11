<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

/**
 * 断言操作符契约(design/12 §2)。
 *
 * 自定义操作符经 Comparison::register(op, impl) 注册,plugin/业务方扩展点。
 */
interface ComparatorInterface
{
    /**
     * @param mixed $actual   实际值
     * @param mixed $expected 期望值(not_null/is_null 等操作符忽略)
     */
    public function compare($actual, $expected): bool;
}
