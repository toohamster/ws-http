<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 变量值包装(design/15 §2.2):区分「未定义」与「值为空串/null」。
 */
final class VarValue
{
    /** @var bool 是否有值 */
    public $defined;

    /** @var mixed string|int|float|bool|null|array */
    public $value;

    /**
     * @param mixed $value
     */
    public function __construct(bool $defined, $value = null)
    {
        $this->defined = $defined;
        $this->value = $value;
    }

    public static function undefined(): self
    {
        return new self(false, null);
    }
}
