<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

/**
 * 提取结果值对象(ExtractorInterface 的返回形态)。
 */
final class ExtractionOutcome
{
    /** @var bool 是否有匹配 */
    public $found;

    /** @var mixed 匹配值(标量或数组;found=false 时为 null) */
    public $value;

    /**
     * @param mixed $value
     */
    public function __construct(bool $found, $value = null)
    {
        $this->found = $found;
        $this->value = $value;
    }

    /**
     * @param mixed $value
     */
    public static function found($value): self
    {
        return new self(true, $value);
    }

    public static function missing(): self
    {
        return new self(false, null);
    }
}
