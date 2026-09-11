<?php

declare(strict_types=1);

namespace Ws\Http\Expression;

/**
 * 词法分析器:表达式字符串 → Token[](design/13 §2 词法)。
 */
final class Lexer
{
    /** @var string */
    private $input;

    /** @var int */
    private $pos = 0;

    /** @var Token[] */
    private $tokens = [];

    /**
     * @return Token[]
     */
    public function tokenize(string $expression): array
    {
        $this->input = $expression;
        $this->pos = 0;
        $this->tokens = [];

        while ($this->pos < \strlen($this->input)) {
            $ch = $this->input[$this->pos];

            switch (true) {
                case $ch === '$' && $this->tokens === []:
                    $this->emit(Token::T_ROOT, '$');
                    break;
                case $ch === '.':
                    $this->emit(Token::T_DOT, '.');
                    break;
                case $ch === '[':
                    $this->emit(Token::T_LBRACKET, '[');
                    break;
                case $ch === ']':
                    $this->emit(Token::T_RBRACKET, ']');
                    break;
                case $ch === '*':
                    $this->emit(Token::T_WILDCARD, '*');
                    break;
                case $ch === ',':
                    $this->emit(Token::T_COMMA, ',');
                    break;
                case $ch === ':':
                    $this->emit(Token::T_COLON, ':');
                    break;
                case $ch === '(':
                    $this->emit(Token::T_LPAREN, '(');
                    break;
                case $ch === ')':
                    $this->emit(Token::T_RPAREN, ')');
                    break;
                case $ch === '?':
                    $this->emit(Token::T_FILTER, '?');
                    break;
                case $ch === '@':
                    $this->emit(Token::T_AT, '@');
                    break;
                case $ch === '=':
                    $this->readComparisonOps();
                    break;
                case $ch === '!':
                    if ($this->peek(1) === '=') {
                        $this->emit(Token::T_NE, '!=');
                        $this->pos += 2;
                    } else {
                        throw $this->error("unexpected '!'");
                    }
                    break;
                case $ch === '<':
                    $this->readLtLe();
                    break;
                case $ch === '>':
                    $this->readGtGe();
                    break;
                case $ch === '\'' || $ch === '"':
                    $this->readQuotedString($ch);
                    break;
                case ctype_digit($ch):
                    $this->readNumber();
                    break;
                case $ch === '-' && $this->peek(1) !== null && ctype_digit($this->peek(1)):
                    $this->readNumber();
                    break;
                case $ch === '/':
                    $this->readRegexLiteral();
                    break;
                case preg_match('/[A-Za-z_\x80-\xFF]/', $ch) === 1:
                    $this->readIdentifier();
                    break;
                case $ch === ' ' || $ch === "\t":
                    $this->pos++;
                    break;
                default:
                    throw $this->error(sprintf("unexpected character '%s'", $ch));
            }
        }

        return $this->tokens;
    }

    private function emit(string $type, $value): void
    {
        $this->tokens[] = new Token($type, $value, $this->pos);
        $this->pos++;
    }

    private function peek(int $offset): ?string
    {
        $i = $this->pos + $offset;

        return $i < \strlen($this->input) ? $this->input[$i] : null;
    }

    private function readComparisonOps(): void
    {
        if ($this->peek(1) === '=') {
            $this->emit(Token::T_EQ, '==');
            $this->pos++; // emit 已前进 1,再进 1 共 2 字节
            // 修正:emit 消费了 '=',此处再消费第二个 '='
            return;
        }
        if ($this->peek(1) === '~') {
            $this->emit(Token::T_REGEX, '=~');
            $this->pos++;
            return;
        }
        throw $this->error("unexpected '=' (expected == or =~)");
    }

    private function readLtLe(): void
    {
        if ($this->peek(1) === '=') {
            $this->emit(Token::T_LE, '<=');
            $this->pos++;
            return;
        }
        $this->emit(Token::T_LT, '<');
    }

    private function readGtGe(): void
    {
        if ($this->peek(1) === '=') {
            $this->emit(Token::T_GE, '>=');
            $this->pos++;
            return;
        }
        $this->emit(Token::T_GT, '>');
    }

    private function readQuotedString(string $quote): void
    {
        $start = $this->pos;
        $this->pos++; // 跳过开引号
        $out = '';

        while ($this->pos < \strlen($this->input)) {
            $ch = $this->input[$this->pos];

            if ($ch === '\\' && $this->pos + 1 < \strlen($this->input)) {
                $next = $this->input[$this->pos + 1];
                // 转义: \' \" \\ 之外原样保留
                $out .= $next;
                $this->pos += 2;
                continue;
            }

            if ($ch === $quote) {
                $this->tokens[] = new Token(Token::T_STRING, $out, $start);
                $this->pos++;
                return;
            }

            $out .= $ch;
            $this->pos++;
        }

        throw $this->error('unterminated string literal', $start);
    }

    private function readNumber(): void
    {
        $start = $this->pos;
        if ($this->input[$this->pos] === '-') {
            $this->pos++; // 负号
        }
        while ($this->pos < \strlen($this->input) && (ctype_digit($this->input[$this->pos]) || $this->input[$this->pos] === '.')) {
            $this->pos++;
        }

        $raw = substr($this->input, $start, $this->pos - $start);
        $this->tokens[] = new Token(Token::T_NUMBER, is_numeric($raw) ? $raw + 0 : $raw, $start);
    }

    /**
     * 正则字面量:/pattern/(含定界符整体作为字符串值,供 preg_match 直接使用)。
     */
    private function readRegexLiteral(): void
    {
        $start = $this->pos;
        $this->pos++; // 跳过开 '/'
        $out = '/';

        while ($this->pos < \strlen($this->input)) {
            $ch = $this->input[$this->pos];

            if ($ch === '\\' && $this->pos + 1 < \strlen($this->input)) {
                $out .= $ch . $this->input[$this->pos + 1];
                $this->pos += 2;
                continue;
            }

            $out .= $ch;
            $this->pos++;

            if ($ch === '/') {
                // 读取修饰符(字母)
                while ($this->pos < \strlen($this->input) && ctype_alpha($this->input[$this->pos])) {
                    $out .= $this->input[$this->pos];
                    $this->pos++;
                }

                $this->tokens[] = new Token(Token::T_STRING, $out, $start);
                return;
            }
        }

        throw $this->error('unterminated regex literal', $start);
    }

    private function readIdentifier(): void
    {
        $start = $this->pos;
        // \x80-\xFF 覆盖 UTF-8 多字节序列的全部字节(中文键名等)
        while ($this->pos < \strlen($this->input) && preg_match('/[A-Za-z0-9_\-\x80-\xFF]/', $this->input[$this->pos]) === 1) {
            $this->pos++;
        }

        $raw = substr($this->input, $start, $this->pos - $start);

        // 字面量关键字(filter 内)
        if (strtolower($raw) === 'true') {
            $this->tokens[] = new Token(Token::T_TRUE, 'true', $start);
            return;
        }
        if (strtolower($raw) === 'false') {
            $this->tokens[] = new Token(Token::T_FALSE, 'false', $start);
            return;
        }
        if (strtolower($raw) === 'null') {
            $this->tokens[] = new Token(Token::T_NULL, 'null', $start);
            return;
        }

        $this->tokens[] = new Token(Token::T_IDENTIFIER, $raw, $start);
    }

    private function error(string $reason, ?int $position = null): ExpressionException
    {
        return new ExpressionException(
            201,
            sprintf('Invalid expression at pos %d: %s in "%s"', $position ?? $this->pos, $reason, $this->input),
            $this->input,
            $position ?? $this->pos
        );
    }
}
