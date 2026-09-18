<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\FullPreset;
use Ws\Http\NanoGpt\MinimalPreset;
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Tool\ListDirTool;
use Ws\Http\NanoGpt\Tool\ReadFileTool;
use Ws\Http\NanoGpt\Tool\WriteFileTool;
use Ws\Http\NanoGpt\ToolRegistry;

/**
 * design/21 N1:Sandbox 三态 / ToolRegistry / 内建文件工具 / Preset。
 */
final class SandboxTest extends TestCase
{
    private string $root;

    /** @var string|null macOS /tmp 符号链接:缓存 sandbox 返回的 realpath */
    private $rootReal = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ws-sandbox-' . uniqid();
        $this->rootReal = null;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * macOS /tmp 是 /private/tmp 符号链接:断言基于 sandbox 返回的 realpath。
     */
    private function expected(string $relative = ''): string
    {
        if ($this->rootReal === null) {
            $probe = new Sandbox($this->root);
            $this->rootReal = $probe->root();
        }

        return $relative === '' ? $this->rootReal : $this->rootReal . '/' . $relative;
    }

    // ---------- Sandbox ----------

    public function testLegalRelativePath(): void
    {
        $sandbox = new Sandbox($this->root);

        self::assertSame($this->expected('work/data.txt'), $sandbox->resolve('work/data.txt'));
    }

    public function testLegalNestedDots(): void
    {
        // c 子目录需存在(realpath 语义);b.txt 不存在 → 尾段归一化
        mkdir($this->root . '/a/c', 0777, true);
        $sandbox = new Sandbox($this->root);

        self::assertSame($this->expected('a/b.txt'), $sandbox->resolve('a/c/../b.txt'));
    }

    public function testTraversalRejected(): void
    {
        $sandbox = new Sandbox($this->root);

        try {
            $sandbox->resolve('../../etc/passwd');
            self::fail('expected AgentException 610');
        } catch (AgentException $e) {
            self::assertSame(610, $e->getCode());
        }
    }

    public function testSymlinkOutsideRejected(): void
    {
        $outside = sys_get_temp_dir() . '/ws-outside-' . uniqid();
        mkdir($outside, 0777, true);
        mkdir($this->root, 0777, true);
        symlink($outside, $this->root . '/leak');

        try {
            $sandbox = new Sandbox($this->root);
            $sandbox->resolve('leak/secret.txt');
            self::fail('expected AgentException 610');
        } catch (AgentException $e) {
            self::assertSame(610, $e->getCode());
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function testRootCreationAndEmptyPath(): void
    {
        self::assertDirectoryDoesNotExist($this->root);
        $sandbox = new Sandbox($this->root);

        self::assertDirectoryExists($this->root);
        self::assertSame($this->expected(), $sandbox->resolve(''));
    }

    // ---------- ToolRegistry ----------

    public function testRegistryRegisterGetHas(): void
    {
        $registry = new ToolRegistry();
        $tool = new ReadFileTool(new Sandbox($this->root));
        $registry->register($tool);

        self::assertTrue($registry->has('read_file'));
        self::assertSame($tool, $registry->get('read_file'));
        self::assertSame(['read_file'], $registry->names());
    }

    public function testRegistryDuplicateThrows603(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new ReadFileTool(new Sandbox($this->root)));

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(603);
        $registry->register(new ReadFileTool(new Sandbox($this->root)));
    }

    public function testRegistryUnknownThrows602(): void
    {
        $registry = new ToolRegistry();

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(602);
        $registry->get('nope');
    }

    public function testRegistryJsonSchemasShape(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new ReadFileTool(new Sandbox($this->root)));

        $schemas = $registry->jsonSchemas();
        self::assertCount(1, $schemas);
        self::assertSame('function', $schemas[0]['type']);
        self::assertSame('read_file', $schemas[0]['function']['name']);
        self::assertSame('object', $schemas[0]['function']['parameters']['type']);
        self::assertSame(['path'], $schemas[0]['function']['parameters']['required']);
    }

    // ---------- 内建工具 ----------

    public function testWriteThenReadRoundtrip(): void
    {
        $sandbox = new Sandbox($this->root);
        $write = new WriteFileTool($sandbox);
        $read = new ReadFileTool($sandbox);

        $result = $write->execute(['path' => 'notes/hello.txt', 'content' => '你好 ws-http']);

        self::assertStringStartsWith('ok:', $result);
        self::assertSame('你好 ws-http', $read->execute(['path' => 'notes/hello.txt']));
    }

    public function testListDir(): void
    {
        $sandbox = new Sandbox($this->root);
        (new WriteFileTool($sandbox))->execute(['path' => 'a.txt', 'content' => 'x']);
        (new WriteFileTool($sandbox))->execute(['path' => 'sub/b.txt', 'content' => 'y']);

        $listing = (new ListDirTool($sandbox))->execute(['path' => '']);

        self::assertStringContainsString('file a.txt', $listing);
        self::assertStringContainsString('dir sub', $listing);
    }

    public function testReadFileTruncation(): void
    {
        $sandbox = new Sandbox($this->root);
        (new WriteFileTool($sandbox))->execute(['path' => 'big.txt', 'content' => str_repeat('A', 100)]);

        $tool = new ReadFileTool($sandbox, 10);

        self::assertStringEndsWith('...[truncated]', $tool->execute(['path' => 'big.txt']));
    }

    public function testToolEscapeViaPathArgRejected(): void
    {
        // 工具层不重复实现穿越检查,但恶意 path 参数必须被 Sandbox 挡住
        $read = new ReadFileTool(new Sandbox($this->root));

        $result = $read->execute(['path' => '../../../etc/passwd']);

        self::assertStringStartsWith('error:', $result);
    }

    // ---------- Preset ----------

    public function testFullPresetBundlesFourTools(): void
    {
        $preset = new FullPreset(new Sandbox($this->root));

        self::assertCount(4, $preset->tools());
        self::assertNull($preset->modelSource());
    }

    public function testMinimalPresetIsPureChat(): void
    {
        $preset = new MinimalPreset();

        self::assertSame([], $preset->tools());
        self::assertNull($preset->modelSource());
    }

    public function testCustomPresetRemovalAffectsSchemas(): void
    {
        $preset = new FullPreset(new Sandbox($this->root));

        $registry = new ToolRegistry();
        foreach ($preset->tools() as $tool) {
            if ($tool->name() !== 'http_get') { // 禁用网络能力
                $registry->register($tool);
            }
        }

        self::assertSame(['read_file', 'write_file', 'list_dir'], $registry->names());
    }
}
