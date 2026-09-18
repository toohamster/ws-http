<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 模型目录来源契约(design/21 §4.2/§8.1):模型列表获取方式随服务不同
 * (链接解析 / 手动指定),Preset 携带其一;null = 壳自行处理。
 */
interface ModelSourceInterface
{
    /**
     * @return array<int, array{id: string, name: string, pricing: string}> 展示用模型目录
     */
    public function models(): array;
}
