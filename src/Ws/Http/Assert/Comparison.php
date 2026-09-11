<?php

declare(strict_types=1);

namespace Ws\Http\Assert;

use Ws\Http\Contract\ComparatorInterface;
use Ws\Http\Expression\ExpressionException;

/**
 * 比较操作符注册表(design/12 §2)。
 *
 * 内建操作符为默认注册项;业务方/plugin 经 register() 扩展(扩展成本 = 一个接口实现)。
 * 全库唯一比较实现:断言器与表达式引擎 filter 共用同一数值化语义。
 */
final class Comparison
{
    /** @var array<string, ComparatorInterface> */
    private static $registry = [];

    private function __construct()
    {
    }

    /**
     * 注册自定义操作符(覆盖同名内建项)。
     */
    public static function register(string $op, ComparatorInterface $impl): void
    {
        self::$registry[$op] = $impl;
    }

    /**
     * 求值一个操作符。
     *
     * @param mixed $actual
     * @param mixed $expected
     * @throws \InvalidArgumentException 未知操作符
     * @throws ExpressionException 数值比较遇到不可数值化操作数(code 203)
     */
    public static function compare(string $op, $actual, $expected): bool
    {
        self::bootBuiltins();

        $impl = self::$registry[$op] ?? null;

        if ($impl === null) {
            throw new \InvalidArgumentException(sprintf('Unknown comparison operator: %s', $op));
        }

        return $impl->compare($actual, $expected);
    }

    /**
     * 内建操作符注册(静态块替代:7.4 无 enum/属性初始化器,由 Comparison 首次使用前调用)。
     */
    public static function bootBuiltins(): void
    {
        if (self::$registry !== []) {
            return;
        }

        self::$registry['eq'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                return \Ws\Http\Assert\Comparison::looseEquals($actual, $expected);
            }
        };

        self::$registry['ne'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                return !\Ws\Http\Assert\Comparison::looseEquals($actual, $expected);
            }
        };

        foreach (['gt' => '>', 'ge' => '>=', 'lt' => '<', 'le' => '<='] as $op => $symbol) {
            self::$registry[$op] = new class($symbol) implements ComparatorInterface {
                private $symbol;

                public function __construct(string $symbol)
                {
                    $this->symbol = $symbol;
                }

                public function compare($actual, $expected): bool
                {
                    if (!is_numeric($actual) || !is_numeric($expected)) {
                        throw new ExpressionException(
                            203,
                            sprintf('Cannot compare numerically: %s vs %s', var_export($actual, true), var_export($expected, true))
                        );
                    }

                    $a = $actual + 0;
                    $b = $expected + 0;

                    switch ($this->symbol) {
                        case '>': return $a > $b;
                        case '>=': return $a >= $b;
                        case '<': return $a < $b;
                        default: return $a <= $b;
                    }
                }
            };
        }

        self::$registry['not_null'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                return $actual !== null && $actual !== '';
            }
        };

        self::$registry['is_null'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                return $actual === null;
            }
        };

        self::$registry['contains'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                if (\is_array($actual)) {
                    return \in_array($expected, $actual, true)
                        || (!\is_array($expected) && \in_array((string) $expected, array_map('strval', $actual), true));
                }

                return \is_string($actual) && \is_string($expected) && strpos($actual, $expected) !== false;
            }
        };

        self::$registry['not_contains'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                if (\is_array($actual)) {
                    return !\in_array($expected, $actual, true)
                        && (!\is_string($expected) || !\in_array($expected, array_map('strval', $actual), true));
                }

                return !(\is_string($actual) && \is_string($expected) && strpos($actual, $expected) !== false);
            }
        };

        self::$registry['starts_with'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                return \is_string($actual) && \is_string($expected)
                    && ($expected === '' || strpos($actual, $expected) === 0);
            }
        };

        self::$registry['ends_with'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                if (!\is_string($actual) || !\is_string($expected)) {
                    return false;
                }
                $len = \strlen($expected);

                return $len === 0 || substr($actual, -$len) === $expected;
            }
        };

        self::$registry['matches'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                if (!\is_string($actual) || !\is_string($expected)) {
                    return false;
                }
                // 无定界符自动包裹(设计 12 §2 matches)
                $pattern = $expected;
                if ($pattern[0] !== '/' && $pattern[0] !== '#' && $pattern[0] !== '~') {
                    $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
                }

                return preg_match($pattern, $actual) === 1;
            }
        };

        foreach (['length_eq' => '=', 'length_gt' => '>', 'length_lt' => '<'] as $op => $symbol) {
            self::$registry[$op] = new class($symbol) implements ComparatorInterface {
                private $symbol;

                public function __construct(string $symbol)
                {
                    $this->symbol = $symbol;
                }

                public function compare($actual, $expected): bool
                {
                    if (\is_array($actual)) {
                        $length = \count($actual);
                    } elseif (\is_string($actual)) {
                        $length = \strlen($actual);
                    } else {
                        return false;
                    }

                    switch ($this->symbol) {
                        case '>': return $length > (int) $expected;
                        case '<': return $length < (int) $expected;
                        default: return $length === (int) $expected;
                    }
                }
            };
        }

        self::$registry['type'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                $typeMap = [
                    'integer' => 'is_integer', 'double' => 'is_float', 'float' => 'is_float',
                    'string' => 'is_string', 'boolean' => 'is_bool', 'array' => 'is_array', 'null' => 'is_null',
                ];

                $check = $typeMap[(string) $expected] ?? null;

                return $check !== null && $check($actual);
            }
        };

        // ---- Watcher 专用组合操作符(响应体语义,非通用注册) ----

        // 头精确匹配:值可为 string|string[],任一匹配即通过(实际值缺失 → fail)
        self::$registry['header_value'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                if ($actual === null) {
                    return false;
                }
                $expectedValues = \is_array($expected) ? $expected : [$expected];

                foreach ($expectedValues as $expectedValue) {
                    if (\Ws\Http\Assert\Comparison::looseEquals($actual, $expectedValue)) {
                        return true;
                    }
                }

                return false;
            }
        };

        // rawBody 为空串
        self::$registry['raw_empty'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                return \is_string($actual) && $actual === '';
            }
        };

        // rawBody 是合法 JSON(修复 C7:json_last_error 判定,"null"/"0" 均有效)
        self::$registry['raw_valid_json'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                if (!\is_string($actual)) {
                    return false;
                }
                json_decode($actual);

                return json_last_error() === JSON_ERROR_NONE;
            }
        };

        // json_root:Response->body 与期望宽松比较(body=false 即解析失败)
        self::$registry['body_json'] = new class implements ComparatorInterface {
            public function compare($actual, $expected): bool
            {
                if ($actual === false) {
                    return false; // JSON 解析失败
                }

                return \Ws\Http\Assert\Comparison::looseEquals($actual, $expected);
            }
        };
    }

    /**
     * 数值化宽松相等(design/12 §2 eq 数值化规则):
     * 双方可数值化 → 数值比较;bool 不与字符串混淆;null 严格;其余字符串化比较。
     *
     * @param mixed $actual
     * @param mixed $expected
     */
    public static function looseEquals($actual, $expected): bool
    {
        if (\is_bool($actual) || \is_bool($expected)) {
            return \is_bool($actual) && \is_bool($expected) && $actual === $expected;
        }

        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (\is_array($actual) || \is_object($actual) || \is_array($expected) || \is_object($expected)) {
            // 结构宽松比较:对象与数组统一为数组形态再比(PHP 的 stdClass == array 恒 false)
            return self::looseNormalize($actual) == self::looseNormalize($expected);
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * 递归对象→数组归一(json_decode 产物与字面量数组互比用)。
     *
     * @param mixed $value
     * @return mixed
     */
    public static function looseNormalize($value)
    {
        if (\is_object($value)) {
            $vars = get_object_vars($value);
            foreach ($vars as &$sub) {
                $sub = self::looseNormalize($sub);
            }

            return $vars;
        }

        if (\is_array($value)) {
            foreach ($value as &$sub) {
                $sub = self::looseNormalize($sub);
            }
        }

        return $value;
    }
}
