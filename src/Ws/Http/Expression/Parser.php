<?php

declare(strict_types=1);

namespace Ws\Http\Expression;

/**
 * 语法分析器:Token[] → CompiledExpression(步骤数组,design/13 §2 文法)。
 */
final class Parser
{
    /** @var Token[] */
    private $tokens;

    /** @var int */
    private $idx = 0;

    /** @var string 原表达式(诊断用) */
    private $source;

    /**
     * @param Token[] $tokens
     */
    public function __construct(array $tokens, string $source)
    {
        $this->tokens = $tokens;
        $this->source = $source;
    }

    /**
     * 解析;产出步骤数组。
     *
     * 步骤形态:
     *   ['type' => 'member', 'name' => string, 'numeric' => bool]
     *   ['type' => 'wildcard']
     *   ['type' => 'index', 'index' => int]
     *   ['type' => 'slice', 'start' => ?int, 'end' => ?int, 'step' => int]
     *   ['type' => 'union', 'indices' => int[]]
     *   ['type' => 'filter', 'expr' => FilterExpr]
     *
     * FilterExpr 形态:
     *   ['kind' => 'existence', 'path' => string[]]
     *   ['kind' => 'compare', 'path' => string[], 'op' => string, 'value' => mixed]
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(): array
    {
        $steps = [];

        // root 可省略
        if ($this->peekType() === Token::T_ROOT) {
            $this->consume();
        }

        if ($this->atEnd()) {
            throw $this->error('empty expression');
        }

        // 裸 identifier 开头(如 "a.b",省略 $)→ 首个 member 步骤
        if ($this->peekType() === Token::T_IDENTIFIER || $this->peekType() === Token::T_STRING) {
            $token = $this->consume();
            $steps[] = ['type' => 'member', 'name' => (string) $token->value, 'numeric' => false];
        }

        while (!$this->atEnd()) {
            $type = $this->peekType();

            if ($type === Token::T_DOT) {
                $this->consume();
                $steps[] = $this->parseAfterDot();
            } elseif ($type === Token::T_LBRACKET) {
                $this->consume();
                $steps[] = $this->parseBracket();
            } else {
                throw $this->error(sprintf("expected '.' or '[', got token '%s'", $type));
            }
        }

        return $steps;
    }

    /**
     * 点号之后:member 或 wildcard(设计 13 文法 path-step)。
     *
     * @return array<string, mixed>
     */
    private function parseAfterDot(): array
    {
        $type = $this->peekType();

        if ($type === Token::T_WILDCARD) {
            $this->consume();
            return ['type' => 'wildcard'];
        }

        if ($type !== Token::T_IDENTIFIER && $type !== Token::T_NUMBER) {
            throw $this->error("expected member name after '.'");
        }

        $token = $this->consume();
        $name = (string) $token->value;

        return [
            'type'    => 'member',
            'name'    => $name,
            'numeric' => $token->type === Token::T_NUMBER,
        ];
    }

    /**
     * '[' 之后:索引/切片/联合/通配/filter。
     *
     * @return array<string, mixed>
     */
    private function parseBracket(): array
    {
        $type = $this->peekType();

        if ($type === Token::T_WILDCARD) {
            $this->consume();
            $this->expectRbracket();
            return ['type' => 'wildcard'];
        }

        if ($type === Token::T_FILTER) {
            $this->consume();
            $expr = $this->parseFilterExpr();
            $this->expectRbracket();
            return ['type' => 'filter', 'expr' => $expr];
        }

        // 引号串作键:$["weird-key"] → member 步骤
        if ($type === Token::T_STRING) {
            $token = $this->consume();
            $this->expectRbracket();
            return ['type' => 'member', 'name' => (string) $token->value, 'numeric' => false];
        }

        // 切片起始省略:[:2] → start=null
        if ($type === Token::T_COLON) {
            $this->consume();
            return $this->parseSliceRest(0, true);
        }

        // index / slice / union
        $indices = [$this->parseIndexToken()];

        while ($this->peekType() === Token::T_COLON) {
            $this->consume();
            return $this->parseSliceRest($indices[0], false);
        }

        while ($this->peekType() === Token::T_COMMA) {
            $this->consume();
            $indices[] = $this->parseIndexToken();
        }

        $this->expectRbracket();

        return ['type' => 'union', 'indices' => $indices];
    }

    /**
     * 切片第二段起:end 与可选 step。
     *
     * @param bool $startOmitted start 段是否省略(如 [:2])
     * @return array<string, mixed>
     */
    private function parseSliceRest(int $start, bool $startOmitted): array
    {
        $end = null;
        $step = 1;

        if ($this->peekType() === Token::T_NUMBER) {
            $end = (int) $this->consume()->value;
        }

        if ($this->peekType() === Token::T_COLON) {
            $this->consume();
            if ($this->peekType() === Token::T_NUMBER) {
                $step = (int) $this->consume()->value;
                if ($step === 0) {
                    throw $this->error('slice step cannot be 0');
                }
            }
        }

        $this->expectRbracket();

        return ['type' => 'slice', 'start' => $startOmitted ? null : $start, 'end' => $end, 'step' => $step];
    }

    /**
     * 解析一个(可能带负号的)索引数字。
     */
    private function parseIndexToken(): int
    {
        $negative = false;
        if ($this->peekType() === Token::T_STRING && is_numeric((string) $this->peekValue())) {
            // 引号包裹的数字(如 ["-1"] 少见,宽容处理)
            $value = (int) (string) $this->consume()->value;
            return $value;
        }

        if ($this->peekType() !== Token::T_NUMBER) {
            throw $this->error('expected index number');
        }

        $token = $this->consume();

        return (int) $token->value;
    }

    /**
     * filter 表达式:?(expr) —— existence 或 comparison(文法 filter-expr)。
     *
     * @return array<string, mixed>
     */
    private function parseFilterExpr(): array
    {
        $this->expectType(Token::T_LPAREN, "expected '(' after '?'");
        $this->expectType(Token::T_AT, "expected '@' in filter");

        // @.path
        $path = [];
        while ($this->peekType() === Token::T_DOT) {
            $this->consume();
            $type = $this->peekType();
            if ($type !== Token::T_IDENTIFIER && $type !== Token::T_NUMBER) {
                throw $this->error("expected member name in filter path");
            }
            $path[] = (string) $this->consume()->value;
        }

        if ($path === []) {
            throw $this->error('filter path cannot be empty');
        }

        // 无操作符 → existence
        if ($this->peekType() === Token::T_RPAREN) {
            $this->consume();
            return ['kind' => 'existence', 'path' => $path];
        }

        // comparison
        $op = $this->parseComparisonOp();
        $value = $this->parseLiteral();
        $this->expectType(Token::T_RPAREN, "expected ')' to close filter");

        return ['kind' => 'compare', 'path' => $path, 'op' => $op, 'value' => $value];
    }

    private function parseComparisonOp(): string
    {
        $type = $this->peekType();
        $this->consume();

        switch ($type) {
            case Token::T_EQ: return '==';
            case Token::T_NE: return '!=';
            case Token::T_GT: return '>';
            case Token::T_GE: return '>=';
            case Token::T_LT: return '<';
            case Token::T_LE: return '<=';
            case Token::T_REGEX: return '=~';
            default:
                throw $this->error('expected comparison operator in filter');
        }
    }

    /**
     * @return mixed
     */
    private function parseLiteral()
    {
        $type = $this->peekType();

        switch ($type) {
            case Token::T_NUMBER:
                return $this->consume()->value;
            case Token::T_STRING:
                return $this->consume()->value;
            case Token::T_TRUE:
                $this->consume();
                return true;
            case Token::T_FALSE:
                $this->consume();
                return false;
            case Token::T_NULL:
                $this->consume();
                return null;
            default:
                throw $this->error('expected literal value in filter');
        }
    }

    private function expectRbracket(): void
    {
        $this->expectType(Token::T_RBRACKET, "expected ']'");
    }

    private function expectType(string $type, string $reason): void
    {
        if ($this->peekType() !== $type) {
            throw $this->error($reason);
        }
        $this->consume();
    }

    private function peekType(): ?string
    {
        return $this->atEnd() ? null : $this->tokens[$this->idx]->type;
    }

    /**
     * @return mixed
     */
    private function peekValue()
    {
        return $this->atEnd() ? null : $this->tokens[$this->idx]->value;
    }

    private function atEnd(): bool
    {
        return $this->idx >= \count($this->tokens);
    }

    private function consume(): Token
    {
        if ($this->atEnd()) {
            throw $this->error('unexpected end of expression');
        }

        return $this->tokens[$this->idx++];
    }

    private function error(string $reason): ExpressionException
    {
        $pos = $this->atEnd() ? (\end($this->tokens)->position ?? 0) : $this->tokens[$this->idx]->position;

        return new ExpressionException(
            201,
            sprintf('Invalid expression at pos %d: %s in "%s"', $pos, $reason, $this->source),
            $this->source,
            $pos
        );
    }
}
