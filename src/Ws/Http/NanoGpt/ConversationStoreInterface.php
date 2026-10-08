<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 会话持久化契约(design/24 §3,M1-b):snapshot 存取,身份(sessionId)由壳持有。
 */
interface ConversationStoreInterface
{
    /**
     * 已有 session id 列表(新→旧)。
     *
     * @return string[]
     */
    public function list(): array;

    /**
     * @return array<string, mixed>|null snapshot(不存在/损坏 = null)
     */
    public function load(string $sessionId): ?array;

    /**
     * @param array<string, mixed> $snapshot Conversation::snapshot 形态(+ savedAt 由实现附加)
     */
    public function save(string $sessionId, array $snapshot): void;

    public function delete(string $sessionId): void;
}
