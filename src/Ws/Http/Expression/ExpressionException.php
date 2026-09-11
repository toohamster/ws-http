<?php

declare(strict_types=1);

namespace Ws\Http\Expression;

use Ws\Http\Exception;

/**
 * 表达式语法/求值异常(design/13 §5)。
 *
 * code 201 = 语法错误(含出错位置);202 = 调用方要求"空集即错";203 = 类型不匹配。
 */
class ExpressionException extends Exception
{
    /** @var string|null 原始表达式 */
    private $expression;

    /** @var int|null 出错位置(语法错误时) */
    private $position;

    public function __construct(int $code, string $message, ?string $expression = null, ?int $position = null)
    {
        $this->expression = $expression;
        $this->position = $position;

        parent::__construct($message, $code);
    }

    public function expression(): ?string
    {
        return $this->expression;
    }

    public function position(): ?int
    {
        return $this->position;
    }
}
