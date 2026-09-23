<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

/**
 * 模型目录来源契约(design/21 §8.1,壳层;§8.2 修订:实现归 ModelProvider 三层适配器)。
 *
 * 壳与 /model 命令只依赖本契约,不感知适配器数量与对象。
 */
interface ModelSourceInterface
{
    /**
     * @return array<int, array{id: string, name: string, pricing: string}> 展示用模型目录
     */
    public function models(): array;
}
