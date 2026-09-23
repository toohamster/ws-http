<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

/**
 * 服务适配器模板层(design/21 §8.2 三层结构):骨架 = 调用 → 容错 → 规范化。
 *
 * 共享语义上提至此(final 锁定,子类不可改写骨架),差异语义(fetch/normalize)下沉具体类。
 * 新服务商 = 继承本类填两个空 + Application 分派方法加一个分支。
 */
abstract class AbstractServiceAdapter implements ModelSourceInterface
{
    /**
     * 模板方法(final):容错(服务失败 → 空列表)是所有适配器共享语义。
     *
     * @return array<int, array{id: string, name: string, pricing: string}>
     */
    final public function models(): array
    {
        try {
            return $this->normalize($this->fetch());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 服务调用(差异空):返回该服务商的原始模型列表。
     *
     * @return array<int, object|array<string, mixed>>
     */
    abstract protected function fetch(): array;

    /**
     * 规范化(差异空):原始列表 → 展示目录(过滤/字段映射也在此,属服务差异)。
     *
     * @param array<int, object|array<string, mixed>> $raw
     * @return array<int, array{id: string, name: string, pricing: string}>
     */
    abstract protected function normalize(array $raw): array;

    /**
     * 自报契约(§8.2):选择标识(写进 settings.adapter;/init --adapter 参数)。
     */
    abstract public static function id(): string;

    /**
     * 自报契约(§8.2):/init 菜单展示文案。
     */
    abstract public static function label(): string;

    /**
     * 自报契约(§8.2):/init 收集输入项(各适配器不同;/init 按此提问,不写死)。
     *
     * @return array<int, array{key: string, prompt: string, default?: string}>
     */
    abstract public static function prompts(): array;
}
