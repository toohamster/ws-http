<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * URL 与查询参数纯函数工具(设计 11 §1.2)。
 *
 * 编码/解码全部使用 PHP 内置函数:parse_url / parse_str / http_build_query。
 * buildHttpQuery 仅负责多维数组拍平(键结构 a[b]);URL 字节级编码交给内置函数。
 */
final class UrlKit
{
    private function __construct()
    {
    }

    /**
     * 递归拍平多维数组/对象:键结构为 a[b][c],CURLFile 对象原样保留。
     *
     * @param array|object $data
     * @return array<string, mixed>
     */
    public static function buildHttpQuery($data, string $parent = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = $parent === '' ? (string) $key : sprintf('%s[%s]', $parent, $key);

            if ($value instanceof \CURLFile) {
                $result[$newKey] = $value;
            } elseif (\is_array($value) || \is_object($value)) {
                $vars = \is_object($value) ? get_object_vars($value) : $value;
                $result = array_merge($result, self::buildHttpQuery($vars, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * URL 规范化:parse_url 拆解 + 按需重编码 + 重组。
     *
     * query 处理策略(避免字节级自实现):
     * - 纯 ASCII(已编码或纯英文)→ 原样保留,不做 decode/encode 往返;
     * - 含未编码非 ASCII 字节(如直接写中文)→ 预编码为 ASCII 后再拆解,
     *   最后 parse_str + http_build_query 重编码。
     *
     * 关键事实:**parse_url 不是字节安全的**(实测会把 UTF-8 多字节字符截断损坏),
     * 因此任何含高位字节的 URL 必须先 rawurlencode 预处理成纯 ASCII 再交给它。
     *
     * 已知取舍:parse_str 会把键中的 `.` 和空格规范化为 `_`(PHP 文档行为),
     * 本库不为此重新实现解析器。
     *
     * @throws \InvalidArgumentException 无 scheme/host 等非法 URL
     */
    public static function encodeUrl(string $url): string
    {
        // 预处理:非 ASCII 字节 → %XX,保证 parse_url 安全(它按单字节扫描)
        if (preg_match('/[\x80-\xFF]/', $url) === 1) {
            $url = (string) preg_replace_callback(
                '/[^\x00-\x7F]+/u',
                static fn (array $m): string => rawurlencode($m[0]),
                $url
            );
        }

        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid URL: %s', $url));
        }

        $query = $parsed['query'] ?? null;

        return $parsed['scheme'] . '://'
            . $parsed['host']
            . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
            . ($parsed['path'] ?? '')
            . ($query !== null ? '?' . $query : '')
            . (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '');
    }
}
