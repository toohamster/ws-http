<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\CommandExecKernel;
use Ws\Http\NanoGpt\DescriptorToolLoader;

/**
 * design/25 C3:声明式工具装载(*.tool.json → JsonCommandTool,与代码形态同源校验)。
 */
final class DescriptorToolLoaderTest extends TestCase
{
    private string $toolsDir;

    private CommandExecKernel $kernel;

    protected function setUp(): void
    {
        $this->toolsDir = sys_get_temp_dir() . '/ws-desc-' . uniqid();
        $this->kernel = new CommandExecKernel($this->toolsDir, 10, 2048);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->toolsDir));
    }

    private function loader(): DescriptorToolLoader
    {
        return new DescriptorToolLoader($this->toolsDir, $this->kernel);
    }

    private function writeTool(string $name, array $spec): void
    {
        file_put_contents(
            $this->toolsDir . '/' . $name . '.tool.json',
            json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    // ---------- 合法装载 ----------

    public function testLoadValidDescriptor(): void
    {
        mkdir($this->toolsDir, 0777, true);
        $this->writeTool('search-text', [
            'name'        => 'search_text',
            'description' => 'Search text in workspace files',
            'command'     => 'grep -rn {pattern} {path}',
            'arguments'   => [
                'pattern' => ['type' => 'string', 'description' => 'pattern', 'required' => true],
                'path'    => ['type' => 'string', 'description' => 'path', 'default' => '.'],
            ],
        ]);

        $tools = $this->loader()->loadAll();

        self::assertCount(1, $tools);
        self::assertSame('search_text', $tools[0]->name());
        self::assertSame('object', $tools[0]->jsonSchema()['type']);
        self::assertSame(['pattern'], $tools[0]->jsonSchema()['required']);
    }

    public function testLoadedToolActuallyRuns(): void
    {
        mkdir($this->toolsDir, 0777, true);
        file_put_contents($this->toolsDir . '/f.txt', "alpha\nbeta\n");
        $this->writeTool('show-file', [
            'name'        => 'show_file',
            'description' => 'cat a file',
            'command'     => 'cat {path}',
            'arguments'   => ['path' => ['type' => 'string', 'description' => 'file', 'required' => true]],
        ]);

        $tool = $this->loader()->loadAll()[0];
        $result = $tool->execute(['path' => 'f.txt']);

        self::assertStringContainsString('alpha', $result);
        self::assertStringContainsString('exit: 0', $result);
    }

    // ---------- 非法形态(613 + 跳过继续) ----------

    public function testInvalidJsonSkippedOthersContinue(): void
    {
        mkdir($this->toolsDir, 0777, true);
        file_put_contents($this->toolsDir . '/broken.tool.json', '{not json');
        $this->writeTool('ok', ['name' => 'ok_tool', 'description' => 'd', 'command' => 'echo {msg}', 'arguments' => ['msg' => ['type' => 'string', 'description' => 'm', 'required' => true]]]);

        $tools = $this->loader()->loadAll();

        self::assertCount(1, $tools, '非法文件跳过,其余照常');
        self::assertSame('ok_tool', $tools[0]->name());
        self::assertFileExists($this->toolsDir . '/.loader-errors.log', '错误留痕供壳展示');
    }

    public function testMissingRequiredFieldThrows613(): void
    {
        mkdir($this->toolsDir, 0777, true);
        $this->writeTool('no-cmd', ['name' => 'x', 'description' => 'd']);

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        $this->loader()->loadFile($this->toolsDir . '/no-cmd.tool.json');
    }

    public function testSkeletonMetacharThrows613ViaBase(): void
    {
        mkdir($this->toolsDir, 0777, true);
        $this->writeTool('evil', ['name' => 'evil_tool', 'description' => 'd', 'command' => 'cat {p} | tee /tmp/x']);

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        $this->loader()->loadFile($this->toolsDir . '/evil.tool.json');
    }

    public function testDenyBinaryInDescriptorThrows613(): void
    {
        mkdir($this->toolsDir, 0777, true);
        $this->writeTool('deleter', ['name' => 'deleter', 'description' => 'd', 'command' => 'rm -rf {path}']);

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        $this->loader()->loadFile($this->toolsDir . '/deleter.tool.json');
    }

    public function testNameConflictThrows613(): void
    {
        mkdir($this->toolsDir, 0777, true);
        $spec = ['name' => 'dup', 'description' => 'd', 'command' => 'echo {m}', 'arguments' => ['m' => ['type' => 'string', 'description' => 'm', 'required' => true]]];
        $this->writeTool('one', $spec);
        $this->writeTool('two', $spec);

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        $this->expectExceptionMessage('duplicated');
        $this->loader()->loadAll();
    }

    public function testToolsDirAutoCreated(): void
    {
        $loader = $this->loader();

        self::assertDirectoryExists($this->toolsDir);
        self::assertSame([], $loader->loadAll(), '空目录 = 零工具,不报错');
    }
}
