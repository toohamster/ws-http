<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * 大小写不敏感的头集合,统一处理请求头合并与响应头解析(design/11 §2)。
 *
 * 内部以小写名索引;首次出现的原始大小写被保留用于输出。
 * 同名多值:add() 追加为数组;get() 返回逗号拼接;all() 返回全量。
 */
final class HeaderBag implements \IteratorAggregate, \Countable
{
    /** @var array<string, array{display: string, values: string[]}> */
    private array $headers = [];

    /** @param array<string, string|string[]> $headers [name => value|values] */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $values = \is_array($value) ? array_map('strval', array_values($value)) : [(string) $value];
            $this->set($name, $values);
        }
    }

    /** @param string|string[] $value */
    public function set(string $name, $value): void
    {
        $values = \is_array($value) ? array_map('strval', array_values($value)) : [$value];
        $key = $this->normalize($name);

        if (isset($this->headers[$key])) {
            $this->headers[$key]['values'] = $values;
        } else {
            $this->headers[$key] = ['display' => $name, 'values' => $values];
        }
    }

    public function add(string $name, string $value): void
    {
        $key = $this->normalize($name);

        if (isset($this->headers[$key])) {
            $this->headers[$key]['values'][] = $value;
        } else {
            $this->headers[$key] = ['display' => $name, 'values' => [$value]];
        }
    }

    /**
     * 大小写不敏感取头;多值返回逗号拼接;缺失返回 null。
     */
    public function get(string $name): ?string
    {
        $values = $this->all($name);

        if ($values === []) {
            return null;
        }

        return implode(', ', $values);
    }

    /**
     * 指定头的全部值(大小写不敏感);缺失返回空数组。
     *
     * @return string[]
     */
    public function all(string $name): array
    {
        return $this->headers[$this->normalize($name)]['values'] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->headers[$this->normalize($name)]);
    }

    public function remove(string $name): void
    {
        unset($this->headers[$this->normalize($name)]);
    }

    /**
     * 原始大小写的头名列表。
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_map(static fn (array $h): string => $h['display'], array_values($this->headers));
    }

    /**
     * [原始名 => string|string[]] 形态输出。
     *
     * @return array<string, string|string[]>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->headers as $header) {
            $result[$header['display']] = \count($header['values']) === 1 ? $header['values'][0] : $header['values'];
        }

        return $result;
    }

    /**
     * 输出 CURLOPT_HTTPHEADER 形态:头名统一小写(设计 11 §1.6)。
     *
     * @return string[]
     */
    public function toCurlHeaders(): array
    {
        $lines = [];
        foreach ($this->headers as $key => $header) {
            $lines[] = $key . ': ' . implode(', ', $header['values']);
        }

        return $lines;
    }

    /**
     * 解析响应原始头块(设计 11 §2 行为 3):
     * - 跳过状态行;`\r\n` 或 `\n` 分行;
     * - 续行(以空白开头)并入上一头;
     * - 空值头(`Expect:`)保留空串值;
     * - 值两端空白去除。
     */
    public static function fromRawHeaders(string $raw): self
    {
        $bag = new self();
        $lastName = null;

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            // 续行:以空白开头且已有前一头
            if ($lastName !== null && ($line[0] === ' ' || $line[0] === "\t")) {
                $header = &$bag->headers[$bag->normalize($lastName)];
                $lastIndex = \count($header['values']) - 1;
                $header['values'][$lastIndex] .= ' ' . trim($line);
                unset($header);
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                // 状态行(HTTP/1.1 200 OK)或非头行:跳过
                $lastName = null;
                continue;
            }

            $name = trim(substr($line, 0, $colon));
            if ($name === '') {
                $lastName = null;
                continue;
            }

            $bag->add($name, trim(substr($line, $colon + 1)));
            $lastName = $name;
        }

        return $bag;
    }

    /**
     * @return \ArrayIterator<string, string|string[]>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->toArray());
    }

    public function count(): int
    {
        return \count($this->headers);
    }

    private function normalize(string $name): string
    {
        return strtolower($name);
    }
}
