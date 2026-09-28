<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

use Ws\Http\NanoGpt\ModelInfo;

/**
 * 服务适配器抽象层(design/21 §8.2 四次评审:通用 Provider)。
 *
 * 骨架 = 取 → 逐条 map(set)→ 容错,final 锁定;实例职责 = 把本服务原始条目
 * set 进 ModelInfo 通用属性模型(Java Bean 心智:契约定义属性,实例填充差异)。
 * 新服务商 = 继承本类实现 fetch/map + 自报契约。
 */
abstract class AbstractServiceAdapter implements ModelSourceInterface
{
    /**
     * 模板方法(final):容错(服务失败 → 空列表)是共享语义。
     *
     * @return array<int, ModelInfo>
     */
    final public function models(): array
    {
        try {
            $out = [];
            foreach ($this->fetch() as $raw) {
                $info = $this->map($raw);
                if ($info !== null) {
                    $out[] = $info;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 服务调用(差异):返回本服务的原始模型列表条目。
     *
     * @return iterable<int, object|array<string, mixed>>
     */
    abstract protected function fetch(): iterable;

    /**
     * 实例职责:set——本服务原始条目 → ModelInfo(字段名/结构差异在此隔绝);
     * 返回 null = 过滤掉该条目(实例行为选择,如 OrcaRouter 非 free)。
     *
     * @param object|array<string, mixed> $raw
     */
    abstract protected function map($raw): ?ModelInfo;

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
