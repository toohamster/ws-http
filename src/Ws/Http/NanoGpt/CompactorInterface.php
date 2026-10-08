<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 压缩策略契约(design/24 §4.2,M2-b):把一段 messages 压缩为一条摘要消息。
 *
 * 分段语义由调用方保证(段边界落在 user 边界);Compactor 不做边界判定(单一职责)。
 */
interface CompactorInterface
{
    /**
     * 把待压缩段(整段,含 role=tool)压缩为一条 wire 格式摘要消息:
     * ['role' => 'user', 'content' => "【历史摘要】..."] —— 作为普通 user 消息回放。
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>
     * @throws AgentException 612 压缩失败(原 messages 不动,调用方"先算后换")
     */
    public function compact(array $messages): array;
}
