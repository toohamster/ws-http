<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 文件会话存储(design/24 §3,M1-b):<dir>/<sessionId>.json,内容 = snapshot + savedAt。
 *
 * 落 .runtime/sessions(草稿区,过期即弃);list 按 mtime 新→旧;
 * 损坏 JSON 容错(视为不存在)。
 */
final class FileConversationStore implements ConversationStoreInterface
{
    /** @var string 存储目录(不存在时 save 自动创建) */
    private $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    public function list(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $files = glob($this->dir . '/*.json') ?: [];
        $mtimes = [];
        foreach ($files as $f) {
            $mtime = @filemtime($f);
            $mtimes[$f] = $mtime === false ? 0 : $mtime;
        }
        arsort($mtimes); // 新→旧

        $ids = [];
        foreach (array_keys($mtimes) as $f) {
            $ids[] = basename($f, '.json');
        }

        return $ids;
    }

    public function load(string $sessionId): ?array
    {
        $raw = @file_get_contents($this->path($sessionId));
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return \is_array($data) ? $data : null; // 损坏 JSON 视为不存在
    }

    public function save(string $sessionId, array $snapshot): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new AgentException(sprintf('Cannot create session store dir "%s"', $this->dir), 610);
        }

        $snapshot['savedAt'] = date('c');
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($this->path($sessionId), $json . "\n") === false) {
            throw new AgentException(sprintf('Cannot save session "%s"', $sessionId), 610);
        }
    }

    public function delete(string $sessionId): void
    {
        @unlink($this->path($sessionId));
    }

    private function path(string $sessionId): string
    {
        return $this->dir . '/' . $sessionId . '.json';
    }
}
