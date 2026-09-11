<?php

declare(strict_types=1);

namespace Ws\Http\Expression;

/**
 * 词法单元(design/13 §2)。
 */
final class Token
{
    public const T_ROOT = 'root';           // $
    public const T_DOT = 'dot';             // .
    public const T_LBRACKET = 'lbracket';   // [
    public const T_RBRACKET = 'rbracket';   // ]
    public const T_WILDCARD = 'wildcard';   // *
    public const T_COMMA = 'comma';         // ,
    public const T_COLON = 'colon';         // :
    public const T_LPAREN = 'lparen';       // (
    public const T_RPAREN = 'rparen';       // )
    public const T_FILTER = 'filter';       // ?
    public const T_AT = 'at';               // @
    public const T_EQ = 'eq';               // ==
    public const T_NE = 'ne';               // !=
    public const T_GT = 'gt';               // >
    public const T_GE = 'ge';               // >=
    public const T_LT = 'lt';               // <
    public const T_LE = 'le';               // <=
    public const T_REGEX = 'regex';         // =~
    public const T_IDENTIFIER = 'identifier';
    public const T_NUMBER = 'number';
    public const T_STRING = 'string';       // 引号串
    public const T_TRUE = 'true';
    public const T_FALSE = 'false';
    public const T_NULL = 'null';

    /** @var string */
    public $type;

    /** @var string|int|float 原文(已解码的字符串值/数值) */
    public $value;

    /** @var int 在原表达式中的起始位置(诊断用) */
    public $position;

    /**
     * @param string|int|float $value
     */
    public function __construct(string $type, $value, int $position)
    {
        $this->type = $type;
        $this->value = $value;
        $this->position = $position;
    }
}
