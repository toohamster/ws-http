<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Memory;

/**
 * design/24 §6(M3):显式长期记忆——Memory(remember/recall/forget/systemPromptBlock)。
 *
 * 钉住:文件持久化往返;maxEntries 淘汰最旧;tag 过滤;systemPromptBlock 拼接与截断;
 * 空条目 null;损坏 JSON 容错。
 */
final class MemoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-mem-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function memory(int $maxEntries = 100): Memory
    {
        return new Memory($this->dir . '/memory.json', $maxEntries);
    }

    public function testRememberRecallRoundtrip(): void
    {
        $m = $this->memory();
        $m->remember('user prefers php74', ['env']);
        $m->remember('project is ws-http');

        $reloaded = $this->memory(); // 新实例 = 从文件读(持久化往返)

        $all = $reloaded->recall();
        self::assertCount(2, $all);
        self::assertSame('user prefers php74', $all[0]['text']);
        self::assertSame(['env'], $all[0]['tags']);
        self::assertIsInt($all[0]['at']);
        self::assertSame('project is ws-http', $all[1]['text']);
    }

    public function testRecallTagFilter(): void
    {
        $m = $this->memory();
        $m->remember('a', ['x']);
        $m->remember('b', ['y']);
        $m->remember('c', ['x', 'y']);

        $tagged = $m->recall('x');

        self::assertCount(2, $tagged);
        self::assertSame('a', $tagged[0]['text']);
        self::assertSame('c', $tagged[1]['text']);
    }

    public function testMaxEntriesEvictsOldest(): void
    {
        $m = $this->memory(2);
        $m->remember('first');
        $m->remember('second');
        $m->remember('third'); // 淘汰 first

        $all = $m->recall();

        self::assertCount(2, $all);
        self::assertSame('second', $all[0]['text']);
        self::assertSame('third', $all[1]['text']);
    }

    public function testForgetByIndex(): void
    {
        $m = $this->memory();
        $m->remember('keep-a');
        $m->remember('drop-me');
        $m->remember('keep-b');

        $m->forget(1);

        $all = $m->recall();
        self::assertCount(2, $all);
        self::assertSame(['keep-a', 'keep-b'], array_column($all, 'text'));
    }

    public function testForgetInvalidIndexIsNoop(): void
    {
        $m = $this->memory();
        $m->remember('only');

        $m->forget(99); // 不抛

        self::assertCount(1, $m->recall());
    }

    public function testSystemPromptBlock(): void
    {
        $m = $this->memory();
        $m->remember('fact one');
        $m->remember('fact two');

        $block = $m->systemPromptBlock();

        self::assertIsString($block);
        self::assertStringContainsString('fact one', $block);
        self::assertStringContainsString('fact two', $block);
    }

    public function testSystemPromptBlockEmptyReturnsNull(): void
    {
        self::assertNull($this->memory()->systemPromptBlock());
    }

    public function testSystemPromptBlockTruncatesOldestWhenOverLimit(): void
    {
        // systemPromptBlock 最多展示 10 条(§6:条目超上限截断最旧);塞 12 条
        $m = $this->memory(100);
        for ($i = 1; $i <= 12; $i++) {
            $m->remember('entry-' . $i);
        }

        $block = (string) $m->systemPromptBlock();

        // 12 条只展示最新 10 条(entry-3..12);最旧两条(entry-1/2)不在
        self::assertSame(10, substr_count($block, '- entry-'));
        self::assertStringContainsString('entry-12', $block);
        self::assertStringContainsString('entry-3', $block);
        self::assertSame(0, preg_match('/- entry-(1|2)\n/', $block));
    }

    public function testCorruptedJsonStartsEmpty(): void
    {
        @mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/memory.json', '{"entries": '); // 截断

        self::assertSame([], $this->memory()->recall()); // 容错,视为空
    }

    public function testEmptyTextIgnored(): void
    {
        $m = $this->memory();
        $m->remember('   '); // 空白条目不写入

        self::assertSame([], $m->recall());
    }
}
