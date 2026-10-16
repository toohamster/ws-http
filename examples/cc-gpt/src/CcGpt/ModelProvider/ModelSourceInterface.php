<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

/**
 * 模型目录来源契约(design/21 §8.1;design/28 D3 修订:对话域抽象收口——
 * 实现薄壳化,内部持有 Transport,capabilities() 委托)。
 *
 * 壳与 /model 命令只依赖本契约,不感知适配器数量与对象。
 */
interface ModelSourceInterface
{
    /**
     * @return array<int, \Ws\Http\NanoGpt\ModelInfo> 展示用模型目录
     */
    public function models(): array;
}
