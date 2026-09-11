<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * 字符串工具(纯函数,与 UrlKit 并列的 core 工具)。
 *
 * 提供 {name} 命名占位符模板提取,作为变量系统 template source 的
 * core 实现(design/15 §3.1)——声明式替代裸正则。
 */
final class StrKit
{
    private function __construct()
    {
    }

    /**
     * 从字符串按 {name} 模式提取命名参数。
     *
     * 用法:
     *   StrKit::extract('/2012/08/12/test.html', '/{year}/{month}/{day}/{title}.html')
     *   // ['year' => '2012', 'month' => '08', 'day' => '12', 'title' => 'test']
     *
     * 注意:相邻占位符(无字面量锚点)的切分按非贪婪实现,建议占位符间有字面量分隔。
     *
     * @param string $string  目标字符串
     * @param string $pattern 模式字符串({name} 占位符,name 为 \w+)
     * @param array<string, mixed> $options delimiters(默认 ['{','}'])/ strip_values /
     *                                      case_insensitive / collapse_whitespace
     * @return array<string, string> 提取的命名参数;匹配失败返回空数组
     */
    public static function extract(string $string, string $pattern, array $options = []): array
    {
        $defaults = [
            'delimiters'         => ['{', '}'],
            'strip_values'       => false,
            'case_insensitive'   => false,
            'collapse_whitespace' => false,
        ];
        $options = array_merge($defaults, $options);

        if ($options['collapse_whitespace']) {
            $string = (string) preg_replace('/\s+/', ' ', $string);
            $pattern = (string) preg_replace('/\s+/', ' ', $pattern);
        }

        if (!\is_array($options['delimiters']) || \count($options['delimiters']) !== 2) {
            return [];
        }

        [$startDelim, $endDelim] = $options['delimiters'];

        $hasEndDelim = $endDelim !== '';

        if ($hasEndDelim) {
            $splitter = '/(' . preg_quote($startDelim, '/') . '\w+' . preg_quote($endDelim, '/') . ')/';
            $extracter = '/' . preg_quote($startDelim, '/') . '(\w+)' . preg_quote($endDelim, '/') . '/';
        } else {
            // 空结束分隔符:匹配到空格或字符串末尾
            $splitter = '/(' . preg_quote($startDelim, '/') . '\w+)/';
            $extracter = '/' . preg_quote($startDelim, '/') . '(\w+)/';
        }

        $parts = preg_split($splitter, $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $regexParts = [];
        foreach ($parts as $part) {
            if (preg_match($extracter, $part, $nameMatches) === 1) {
                // 空结束分隔符时,分隔符已从模式剥离但目标串中仍存在 → 补回字面量
                if (!$hasEndDelim) {
                    $regexParts[] = preg_quote($startDelim, '/');
                }
                $regexParts[] = '(?P<' . $nameMatches[1] . '>.+?)';
            } else {
                $regexParts[] = preg_quote($part, '/');
            }
        }

        $expanded = '/^' . implode('', $regexParts) . '$/';
        if ($options['case_insensitive']) {
            $expanded .= 'i';
        }

        if (preg_match($expanded, $string, $matches) !== 1) {
            return [];
        }

        $result = [];
        foreach ($matches as $key => $value) {
            if (\is_string($key)) {
                $result[$key] = $options['strip_values'] ? trim($value) : $value;
            }
        }

        return $result;
    }
}
