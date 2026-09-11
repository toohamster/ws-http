<?php

declare(strict_types=1);

namespace Ws\Http\Expression;

/**
 * JSONPath 子集求值器(design/13)。
 *
 * - 宽容求值:结构性不匹配(缺失键/标量下钻/索引越界)一律"无匹配"(空集),不抛异常;
 * - 断言与变量提取共用本引擎(单一事实来源);
 * - 解析结果 memoize(同一表达式反复求值只解析一次)。
 */
final class ExpressionEvaluator
{
    /** @var array<string, array<int, array<string, mixed>>> 表达式 → 已解析步骤 */
    private $memo = [];

    /** @var Lexer */
    private $lexer;

    public function __construct()
    {
        $this->lexer = new Lexer();
    }

    /**
     * 解析 + 求值;语法错误抛 ExpressionException(201)。
     *
     * @param mixed $data 根数据
     */
    public function evaluate($data, string $expression): EvaluationResult
    {
        $steps = $this->compile($expression);

        // 当前节点集合;初始为 [根]
        $current = [$data];

        foreach ($steps as $step) {
            $next = [];
            foreach ($current as $node) {
                $next = array_merge($next, $this->applyStep($node, $step));
            }
            $current = $next;

            if ($current === []) {
                return new EvaluationResult([]);
            }
        }

        return new EvaluationResult($current);
    }

    /**
     * 静态校验(仅解析,不求值);非法抛 ExpressionException(201)。
     */
    public function validate(string $expression): void
    {
        $this->compile($expression);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function compile(string $expression): array
    {
        if (isset($this->memo[$expression])) {
            return $this->memo[$expression];
        }

        if (trim($expression) === '') {
            throw new ExpressionException(201, 'Invalid expression: empty', $expression, 0);
        }

        $tokens = $this->lexer->tokenize($expression);
        $steps = (new Parser($tokens, $expression))->parse();

        $this->memo[$expression] = $steps;

        return $steps;
    }

    /**
     * 单步应用;返回 [节点...](宽容:不匹配返回空)。
     *
     * @param mixed $node
     * @param array<string, mixed> $step
     * @return mixed[]
     */
    private function applyStep($node, array $step): array
    {
        switch ($step['type']) {
            case 'member':
                return $this->applyMember($node, $step['name'], $step['numeric']);
            case 'wildcard':
                return $this->applyWildcard($node);
            case 'index':
            case 'union':
                $indices = $step['type'] === 'index' ? [$step['index']] : $step['indices'];
                return $this->applyIndices($node, $indices);
            case 'slice':
                return $this->applySlice($node, $step['start'], $step['end'], $step['step']);
            case 'filter':
                return $this->applyFilter($node, $step['expr']);
            default:
                return [];
        }
    }

    /**
     * @param mixed $node
     * @return mixed[]
     */
    private function applyMember($node, string $name, bool $numeric): array
    {
        if (\is_array($node)) {
            if (array_key_exists($name, $node)) {
                return [$node[$name]];
            }
            // 数组上的数字串 member 按索引(设计 13 §3.2)
            if (ctype_digit($name) && array_key_exists((int) $name, $node)) {
                return [$node[(int) $name]];
            }
            return [];
        }

        if (\is_object($node)) {
            $vars = get_object_vars($node);
            if (array_key_exists($name, $vars)) {
                return [$vars[$name]];
            }
            return [];
        }

        return []; // 标量下钻 → 无匹配
    }

    /**
     * @param mixed $node
     * @return mixed[]
     */
    private function applyWildcard($node): array
    {
        if (\is_array($node)) {
            return array_values($node);
        }

        if (\is_object($node)) {
            return array_values(get_object_vars($node));
        }

        return [];
    }

    /**
     * @param mixed $node
     * @param int[] $indices
     * @return mixed[]
     */
    private function applyIndices($node, array $indices): array
    {
        if (!\is_array($node) && !\is_object($node)) {
            return [];
        }

        if (\is_object($node)) {
            $node = get_object_vars($node);
        }

        $out = [];
        foreach ($indices as $index) {
            $resolved = $index < 0 ? \count($node) + $index : $index;
            if ($resolved >= 0 && array_key_exists($resolved, $node)) {
                $out[] = $node[$resolved];
            }
            // 越界 → 剔除
        }

        return $out;
    }

    /**
     * 切片(Python 语义,design/13 §3.2)。
     *
     * @param mixed $node
     * @return mixed[]
     */
    private function applySlice($node, ?int $start, ?int $end, int $step): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $values = array_values($node);
        $len = \count($values);

        if ($len === 0) {
            return [];
        }

        // 归一化
        $norm = static function (?int $v, int $default) use ($len): int {
            if ($v === null) {
                return $default;
            }
            $resolved = $v < 0 ? $len + $v : $v;
            return max(0, min($len, $resolved));
        };

        $from = $norm($start, 0);
        $to = $norm($end, $len);

        $out = [];
        if ($step > 0) {
            for ($i = $from; $i < $to; $i += $step) {
                $out[] = $values[$i];
            }
        } else {
            for ($i = $to - 1; $i >= $from; $i += $step) {
                $out[] = $values[$i];
            }
        }

        return $out;
    }

    /**
     * filter:对数组元素/对象属性值逐个求 filter-expr(设计 13 §3.2)。
     *
     * @param mixed $node
     * @param array<string, mixed> $expr
     * @return mixed[]
     */
    private function applyFilter($node, array $expr): array
    {
        if (\is_array($node)) {
            $candidates = array_values($node);
        } elseif (\is_object($node)) {
            $candidates = array_values(get_object_vars($node));
        } else {
            return []; // 标量集合上 @.path 恒假
        }

        $out = [];
        foreach ($candidates as $candidate) {
            if ($this->evalFilterExpr($candidate, $expr)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @param mixed $candidate
     * @param array<string, mixed> $expr
     */
    private function evalFilterExpr($candidate, array $expr): bool
    {
        // @.path 解析到候选节点的值;不存在 → 无值
        $current = $candidate;
        foreach ($expr['path'] as $segment) {
            if (\is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (\is_object($current)) {
                $vars = get_object_vars($current);
                if (array_key_exists($segment, $vars)) {
                    $current = $vars[$segment];
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }

        if ($expr['kind'] === 'existence') {
            // 非空即真(设计 13 §3.2):非 null 且非空串
            return $current !== null && $current !== '';
        }

        return $this->compare($current, $expr['op'], $expr['value']);
    }

    /**
     * filter 比较语义:数字串宽容比较;=~ 用 PCRE。
     *
     * @param mixed $actual
     * @param mixed $expected
     */
    private function compare($actual, string $op, $expected): bool
    {
        if ($op === '==') {
            return $this->looseEquals($actual, $expected);
        }
        if ($op === '!=') {
            return !$this->looseEquals($actual, $expected);
        }
        if ($op === '=~') {
            if (!\is_string($actual) || !\is_string($expected)) {
                return false;
            }
            return preg_match($expected, $actual) === 1;
        }

        // 数值比较:两侧均可数值化,否则无匹配(宽容)
        if (!is_numeric($actual) || !is_numeric($expected)) {
            return false;
        }

        $a = $actual + 0;
        $b = $expected + 0;

        switch ($op) {
            case '>': return $a > $b;
            case '>=': return $a >= $b;
            case '<': return $a < $b;
            case '<=': return $a <= $b;
            default: return false;
        }
    }

    /**
     * 宽松相等:数值化后严格比较(design/12 §2 数值化规则,与断言器一致)。
     *
     * @param mixed $actual
     * @param mixed $expected
     */
    private function looseEquals($actual, $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (string) ($actual + 0) === (string) ($expected + 0)
                || (float) $actual === (float) $expected;
        }

        if (\is_bool($actual) || \is_bool($expected)) {
            return \is_bool($actual) && \is_bool($expected) && $actual === $expected;
        }

        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        return (string) $actual === (string) $expected;
    }
}
