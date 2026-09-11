<?php

declare(strict_types=1);

namespace Ws\Http\Assert;

/**
 * 单条断言结果(收集式,design/12 §5)。
 */
final class AssertionResult
{
    /** @var Assertion */
    public $assertion;

    /** @var bool */
    public $passed;

    /** @var string|null 失败时按 §4.2 模板;通过为 null */
    public $message;

    /** @var mixed 实际值(报告用) */
    public $actual;

    /**
     * @param mixed $actual
     */
    public function __construct(Assertion $assertion, bool $passed, ?string $message, $actual)
    {
        $this->assertion = $assertion;
        $this->passed = $passed;
        $this->message = $message;
        $this->actual = $actual;
    }
}
