<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Ccgpt;

use CcGpt\Command\Init;
use CcGpt\Command\Model;
use CcGpt\CommandRegistry;
use CcGpt\Context;
use CcGpt\ModelSourceInterface;
use CcGpt\Settings;
use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use PHPUnit\Framework\TestCase;

/**
 * design/21 N3:壳单测(CommandRegistry / Settings / Model 命令 / Init 命令;UI 渲染不测)。
 */
final class CcGptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-ccgpt-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function context(array $settingsData = []): Context
    {
        return new Context(new Output(fopen('php://memory', 'w+', false), false), new Input(), new Settings($this->dir, $settingsData));
    }

    // ---------- CommandRegistry ----------

    public function testRegistryRegisterGetHas(): void
    {
        $registry = new CommandRegistry();
        $registry->register(new Model());

        self::assertTrue($registry->has('model'));
        self::assertInstanceOf(Model::class, $registry->get('model'));
    }

    public function testRegistryDuplicateThrows(): void
    {
        $registry = new CommandRegistry();
        $registry->register(new Model());

        $this->expectException(\InvalidArgumentException::class);
        $registry->register(new Model());
    }

    public function testRegistryUnknownThrows(): void
    {
        $registry = new CommandRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->get('nope');
    }

    // ---------- Settings ----------

    public function testSettingsReadWriteRoundtrip(): void
    {
        $settings = new Settings($this->dir);
        $settings->set('apiKey', 'sk-test');
        $settings->set('baseUrl', 'https://orcarouter.ai/v1');
        $settings->save();

        $reloaded = new Settings($this->dir);

        self::assertSame('sk-test', $reloaded->get('apiKey'));
        self::assertSame('https://orcarouter.ai/v1', $reloaded->get('baseUrl'));
        self::assertFileExists($this->dir . '/.settings.json');
    }

    public function testSettingsMissingFileDefaultsEmpty(): void
    {
        $settings = new Settings($this->dir);

        self::assertNull($settings->get('apiKey'));
        self::assertSame('fallback', $settings->get('anything', 'fallback'));
    }

    // ---------- /model 命令 ----------

    public function testModelSwitchByIdAndIndex(): void
    {
        $context = $this->context();
        $context->modelSource = new class implements ModelSourceInterface {
            public function models(): array
            {
                return [
                    ['id' => 'free-mini', 'name' => 'Free Mini', 'pricing' => '0'],
                    ['id' => 'free-pro', 'name' => 'Free Pro', 'pricing' => '0'],
                ];
            }
        };

        $cmd = new Model();

        // 按序号切换
        $cmd->execute(['2'], $context);
        self::assertSame('free-pro', $context->model);

        // 按 id 切换
        $cmd->execute(['free-mini'], $context);
        self::assertSame('free-mini', $context->model);

        // 越界序号不崩溃
        $cmd->execute(['99'], $context);
        self::assertSame('free-mini', $context->model);
    }

    public function testModelWithoutSourceShowsHint(): void
    {
        $context = $this->context();
        $context->modelSource = null;

        $exit = (new Model())->execute([], $context);

        self::assertTrue($exit); // 不退出 REPL
        self::assertSame('', $context->model);
    }

    // ---------- /init 命令 ----------

    public function testInitWritesSettings(): void
    {
        $context = $this->context();

        (new Init())->execute(['sk-abc', 'https://orcarouter.ai/v1'], $context);

        self::assertSame('sk-abc', $context->settings->get('apiKey'));
        self::assertSame('https://orcarouter.ai/v1', $context->settings->get('baseUrl'));
        self::assertFileExists($this->dir . '/.settings.json');
    }

    public function testInitEmptyKeyAborts(): void
    {
        $context = $this->context();

        $exit = (new Init())->execute([''], $context);

        self::assertTrue($exit);
        self::assertNull($context->settings->get('apiKey'));
    }

    // ---------- 目录三区(design/21 §8 评审修订) ----------

    public function testDefaultWorkAndRuntimeLayoutUnderRepoRoot(): void
    {
        $repoRoot = sys_get_temp_dir() . '/ws-ccgpt-repo-' . uniqid();
        mkdir($repoRoot, 0777, true);

        try {
            $app = new \CcGpt\Application($repoRoot, new Output(fopen('php://memory', 'w+', false), false), new Input());

            self::assertDirectoryExists($repoRoot . '/.work', '默认沙箱 root = <壳根>/.work');
            self::assertDirectoryExists($repoRoot . '/.runtime', '草稿区常驻目录自动创建');
            self::assertSame($repoRoot . '/.runtime', $app->context()->runtimeDir);
            self::assertSame($repoRoot, $app->context()->settings->dir(), 'settings 在壳根,不进 .work/.runtime');
        } finally {
            exec('rm -rf ' . escapeshellarg($repoRoot));
        }
    }

    public function testWorkOverride(): void
    {
        $repoRoot = sys_get_temp_dir() . '/ws-ccgpt-repo2-' . uniqid();
        $workDir = sys_get_temp_dir() . '/ws-ccgpt-work-' . uniqid();
        mkdir($repoRoot, 0777, true);

        try {
            $app = new \CcGpt\Application($repoRoot, new Output(fopen('php://memory', 'w+', false), false), new Input(), $workDir);

            self::assertDirectoryExists($workDir, '--work 覆盖沙箱 root');
            self::assertDirectoryExists($repoRoot . '/.runtime', '草稿区仍为壳根下 .runtime');
        } finally {
            exec('rm -rf ' . escapeshellarg($repoRoot) . ' ' . escapeshellarg($workDir));
        }
    }
}
