<?php

declare(strict_types=1);

namespace CcGpt;

/**
 * 模型目录来源(design/21 §8.1,壳层契约):模型列表获取方式随服务不同。
 */
interface ModelSourceInterface
{
    /**
     * @return array<int, array{id: string, name: string, pricing: string}> 展示用模型目录
     */
    public function models(): array;
}
