<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 最小预设(design/21 §4.2):纯对话,零工具——"禁用某能力"场景的一行解。
 */
final class MinimalPreset implements Preset
{
    public function tools(): array
    {
        return [];
    }

    public function modelSource(): ?ModelSourceInterface
    {
        return null;
    }
}
