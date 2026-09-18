<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 出厂预设集(design/21 §4.2):装配期概念——打包"一组出厂内容"供整体换装。
 *
 * 边界:不碰 agent loop 语义(不覆盖 maxTurns/systemPrompt/model);
 * 无注册表/发现机制;禁用 = 选 MinimalPreset 或从 tools() 移除。
 */
interface Preset
{
    /**
     * @return ToolInterface[] 出厂工具集(使用者可在此基础上增删)
     */
    public function tools(): array;

    /** 模型目录来源;null = 壳自行处理 */
    public function modelSource(): ?ModelSourceInterface;
}
