<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * ${var} 深替换(design/15 §4)。
 *
 * - 占位符唯一语法 ${name}(裸 $name 不是占位符);
 * - 整串完全匹配:标量保留原类型,复合值(数组/对象)直接返回;
 * - 局部嵌入:标量字符串化,复合值 JSON 编码;
 * - 未定义变量 → InvalidArgumentException(消息含变量名,不做静默替换)。
 */
final class ScenarioResolver
{
    private const PLACEHOLDER = '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /**
     * 深替换对象树(步骤的可替换面:url/headers/auth/proxy/body.content/timeout)。
     *
     * @param mixed $value
     * @return mixed
     */
    public function resolve($value, VariableScope $scope)
    {
        if (\is_string($value)) {
            return $this->resolveString($value, $scope);
        }

        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->resolve($item, $scope);
            }

            return $out;
        }

        if (\is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->resolve($item, $scope);
            }

            return $value;
        }

        return $value; // 标量原样
    }

    /**
     * @return mixed
     */
    private function resolveString(string $value, VariableScope $scope)
    {
        // 整串完全匹配:单占位符占满全串
        if (preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $value, $m) === 1) {
            $varValue = $scope->get($m[1]);
            if (!$varValue->defined) {
                throw $this->undefined($m[1]);
            }

            return $varValue->value; // 类型保留(标量/复合均原样)
        }

        // 局部嵌入
        return preg_replace_callback(self::PLACEHOLDER, function (array $m) use ($scope): string {
            $varValue = $scope->get($m[1]);
            if (!$varValue->defined) {
                throw $this->undefined($m[1]);
            }

            return $this->stringify($varValue->value);
        }, $value);
    }

    /**
     * @param mixed $value
     */
    private function stringify($value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value) || $value === null) {
            return (string) $value;
        }

        // 复合值局部嵌入 → JSON 编码(design/15 §4.2)
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    private function undefined(string $name): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            sprintf('Undefined variable "%s" in step (declare it in variables or extract it in a previous step)', $name)
        );
    }
}
