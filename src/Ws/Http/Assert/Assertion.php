<?php

declare(strict_types=1);

namespace Ws\Http\Assert;

/**
 * 断言值对象(design/12 §3)。
 */
final class Assertion
{
    /** @var string 'status' | 'json' | 'header' | 'raw_body' | 'time' */
    public $source;

    /** @var string source=json → JSONPath;source=header → 头名;其余空 */
    public $path;

    /** @var string 操作符名(Comparison 注册表) */
    public $op;

    /** @var mixed 期望值(not_null/is_null 忽略) */
    public $expected;

    /** @var string|null 断言别名(报告中显示) */
    public $name;

    /**
     * @param mixed $expected
     */
    public function __construct(string $source, string $path, string $op, $expected = null, ?string $name = null)
    {
        $this->source = $source;
        $this->path = $path;
        $this->op = $op;
        $this->expected = $expected;
        $this->name = $name;
    }
}
