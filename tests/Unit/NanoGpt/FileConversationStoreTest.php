<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Conversation;
use Ws\Http\NanoGpt\FileConversationStore;

/**
 * design/24 §3(M1-b):会话持久化——ConversationStoreInterface + FileConversationStore。
 *
 * 钉住:save/load 往返(snapshot + savedAt);list 新→旧;delete;损坏 JSON 容错(视为不存在)。
 */
final class FileConversationStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-store-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function snapshot(string $userContent): array
    {
        $c = new Conversation(['role' => 'system', 'content' => 's']);
        $c->append(['role' => 'user', 'content' => $userContent]);
        $c->accumulateUsage((object) ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5]);

        return $c->snapshot();
    }

    public function testSaveLoadRoundtrip(): void
    {
        $store = new FileConversationStore($this->dir);
        $snap = $this->snapshot('hello');

        $store->save('20261008-120001', $snap);
        $loaded = $store->load('20261008-120001');

        self::assertNotNull($loaded);
        self::assertSame(1, $loaded['v']);
        self::assertSame('hello', $loaded['messages'][0]['content']);
        self::assertSame(5, $loaded['usage']['total_tokens']);
        self::assertArrayHasKey('savedAt', $loaded); // snapshot + savedAt
        self::assertFileExists($this->dir . '/20261008-120001.json');
    }

    public function testLoadMissingReturnsNull(): void
    {
        $store = new FileConversationStore($this->dir);

        self::assertNull($store->load('no-such-id'));
    }

    public function testLoadCorruptedJsonReturnsNull(): void
    {
        $store = new FileConversationStore($this->dir);
        @mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/broken.json', '{"v": 1, "messages": '); // 截断 JSON

        self::assertNull($store->load('broken'));
    }

    public function testListNewestFirst(): void
    {
        $store = new FileConversationStore($this->dir);
        $store->save('20261008-100001', $this->snapshot('a'));
        sleep(1); // 保证 filemtime 递增(FileConversationStore 以 mtime 排序)
        $store->save('20261008-100002', $this->snapshot('b'));
        sleep(1);
        $store->save('20261008-100003', $this->snapshot('c'));

        self::assertSame(
            ['20261008-100003', '20261008-100002', '20261008-100001'],
            $store->list()
        );
    }

    public function testListEmptyDir(): void
    {
        $store = new FileConversationStore($this->dir);

        self::assertSame([], $store->list());
    }

    public function testDeleteRemovesFile(): void
    {
        $store = new FileConversationStore($this->dir);
        $store->save('20261008-120001', $this->snapshot('x'));

        $store->delete('20261008-120001');

        self::assertNull($store->load('20261008-120001'));
        self::assertSame([], $store->list());
    }

    public function testDeleteMissingIsNoop(): void
    {
        $store = new FileConversationStore($this->dir);

        $store->delete('ghost'); // 不抛

        self::assertSame([], $store->list());
    }

    public function testSaveOverwritesExisting(): void
    {
        $store = new FileConversationStore($this->dir);
        $store->save('20261008-120001', $this->snapshot('v1'));
        $store->save('20261008-120001', $this->snapshot('v2'));

        $loaded = $store->load('20261008-120001');

        self::assertSame('v2', $loaded['messages'][0]['content']);
        self::assertCount(1, $store->list());
    }

    public function testRestoreRoundtripViaConversation(): void
    {
        $store = new FileConversationStore($this->dir);
        $store->save('s1', $this->snapshot('resume me'));

        $restored = Conversation::restore($store->load('s1'));

        self::assertSame(['role' => 'user', 'content' => 'resume me'], $restored->messages()[0]);
        self::assertSame(5, $restored->usage()['total_tokens']);
    }
}
