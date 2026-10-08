<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 显式长期记忆(design/24 §6,M3):用户显式要求保留的事实条目(只做用户显式写,
 * 模型经工具自主写列预留——错记污染风险)。落 .work/memory.json(同产出物生命周期)。
 *
 * 消费:systemPromptBlock() 经 Conversation::setSystem 运行期注入——remember 后
 * 下一轮即生效,无需重建 Agent(setSystem 存在的动机,design/24 §2)。
 */
final class Memory
{
    private const BLOCK_MAX_ENTRIES = 10;

    /** @var string 存储文件路径 */
    private $file;

    /** @var int 条目上限(超限淘汰最旧) */
    private $maxEntries;

    /** @var array<int, array{text: string, tags: string[], at: int}> */
    private $entries;

    public function __construct(string $file, int $maxEntries = 100)
    {
        $this->file = $file;
        $this->maxEntries = $maxEntries;
        $this->entries = $this->read();
    }

    /**
     * 追加条目(带时间戳);超上限时丢弃最旧(调用方经 recall 对比可感知,壳可提示)。
     *
     * @param string[] $tags
     */
    public function remember(string $text, array $tags = []): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $this->entries[] = ['text' => $text, 'tags' => array_values($tags), 'at' => time()];
        if (count($this->entries) > $this->maxEntries) {
            $this->entries = array_slice($this->entries, -$this->maxEntries); // 丢最旧
        }
        $this->write();
    }

    /**
     * @return array<int, array{text: string, tags: string[], at: int}> 全部或按 tag 过滤
     */
    public function recall(?string $tag = null): array
    {
        if ($tag === null) {
            return $this->entries;
        }

        $out = [];
        foreach ($this->entries as $e) {
            if (in_array($tag, $e['tags'], true)) {
                $out[] = $e;
            }
        }

        return $out;
    }

    public function forget(int $index): void
    {
        if (!isset($this->entries[$index])) {
            return;
        }
        array_splice($this->entries, $index, 1);
        $this->write();
    }

    /**
     * system prompt 附加段;无条目返回 null;展示超 BLOCK_MAX_ENTRIES 截断最旧。
     */
    public function systemPromptBlock(): ?string
    {
        if ($this->entries === []) {
            return null;
        }

        $shown = array_slice($this->entries, -self::BLOCK_MAX_ENTRIES);

        $lines = ["[长期记忆]"];
        foreach ($shown as $e) {
            $lines[] = '- ' . $e['text'];
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{text: string, tags: string[], at: int}>
     */
    private function read(): array
    {
        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        $entries = \is_array($data) && \is_array($data['entries'] ?? null) ? $data['entries'] : [];

        $out = [];
        foreach ($entries as $e) {
            if (!\is_array($e) || !isset($e['text'])) {
                continue;
            }
            $out[] = [
                'text' => (string) $e['text'],
                'tags' => \is_array($e['tags'] ?? null) ? array_values(array_map('strval', $e['tags'])) : [],
                'at'   => (int) ($e['at'] ?? 0),
            ];
        }

        return $out;
    }

    private function write(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AgentException(sprintf('Cannot create memory dir "%s"', $dir), 610);
        }

        $json = json_encode(['entries' => $this->entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($this->file, $json . "\n") === false) {
            throw new AgentException(sprintf('Cannot write memory file "%s"', $this->file), 610);
        }
    }
}
