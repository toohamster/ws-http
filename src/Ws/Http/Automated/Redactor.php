<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 敏感值脱敏(design/16 §3.3 + design/15 §2.1.1)。
 *
 * secret 变量值 / Authorization 头 / cookie 头 / auth 凭据 在报告输出中替换为 ***(长度保留)。
 * 纯函数工具,Runner/Report/CLI 共用。
 */
final class Redactor
{
    private const MASK = '***';

    private function __construct()
    {
    }

    /**
     * 用真实值映射表脱敏任意输出值(递归数组/对象;字符串中出现的敏感值也替换)。
     *
     * @param mixed $output
     * @param mixed[] $secretValues 真实敏感值列表(仅字符串项生效,其余忽略)
     * @return mixed
     */
    public static function apply($output, array $secretValues)
    {
        $stringSecrets = [];
        foreach ($secretValues as $value) {
            if (\is_string($value) && $value !== '') {
                $stringSecrets[] = $value;
            }
        }

        if ($stringSecrets === []) {
            return $output;
        }

        return self::redact($output, $stringSecrets);
    }

    /**
     * @param mixed $output
     * @param string[] $secretValues
     * @return mixed
     */
    private static function redact($output, array $secretValues)
    {
        if (\is_string($output)) {
            foreach ($secretValues as $secret) {
                if (strpos($output, $secret) !== false) {
                    $output = str_replace($secret, self::MASK, $output);
                }
            }

            return $output;
        }

        if (\is_array($output)) {
            foreach ($output as $key => $value) {
                $output[$key] = self::redact($value, $secretValues);
            }

            return $output;
        }

        if (\is_object($output)) {
            foreach (get_object_vars($output) as $key => $value) {
                $output->{$key} = self::redact($value, $secretValues);
            }

            return $output;
        }

        return $output;
    }
}
