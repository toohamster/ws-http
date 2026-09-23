<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Ccgpt;

use CcGpt\Command\Init;
use CcGpt\Context;
use CcGpt\ModelProvider\GenericAdapter;
use CcGpt\ModelProvider\OrcaRouterAdapter;
use CcGpt\ModelProvider\StaticAdapter;
use CcGpt\Settings;
use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use PHPUnit\Framework\TestCase;

/**
 * design/21 §8.2:ModelProvider 三层适配器 + /init 显式选择分派。
 *
 * 仅围绕项目给定的 orcarouter 配置语义;真实链路由 examples/cc-gpt/bin/smoke 覆盖。
 */
final class ModelProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-mp-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function context(array $settingsData = []): Context
    {
        return new Context(new Output(fopen('php://memory', 'w+', false), false), new Input(), new Settings($this->dir, $settingsData));
    }

    // ---------- 三具体适配器:自报契约 ----------

    public function testAdaptersSelfReport(): void
    {
        self::assertSame('orcarouter', OrcaRouterAdapter::id());
        self::assertSame('generic', GenericAdapter::id());
        self::assertSame('static', StaticAdapter::id());

        // /init 菜单只含交互型适配器(static 不进菜单)
        $menuIds = [];
        foreach (Init::adapterClasses() as $class) {
            $menuIds[] = $class::id();
        }
        self::assertSame(['orcarouter', 'generic'], $menuIds);
        self::assertStringContainsString('not in /init menu', StaticAdapter::label());
    }

    public function testAdapterPrompts(): void
    {
        // orcarouter/generic:apiKey + baseUrl(各带默认);static:无输入项
        $orcaKeys = array_map(static fn (array $p): string => $p['key'], OrcaRouterAdapter::prompts());
        self::assertSame(['apiKey', 'baseUrl'], $orcaKeys);

        $genericDefaults = [];
        foreach (GenericAdapter::prompts() as $p) {
            $genericDefaults[$p['key']] = $p['default'] ?? '';
        }
        self::assertSame('https://api.openai.com/v1', $genericDefaults['baseUrl']);

        self::assertSame([], StaticAdapter::prompts());
    }

    // ---------- StaticAdapter(静态档案差异空) ----------

    public function testStaticAdapterReturnsListAsIs(): void
    {
        $models = [
            ['id' => 'qwen-max', 'name' => 'Qwen Max', 'pricing' => '0.002'],
        ];

        self::assertSame($models, (new StaticAdapter($models))->models());
        self::assertSame([], (new StaticAdapter([]))->models());
    }

    // ---------- /init 参数化形态(--adapter)与 settings.adapter 定位 ----------

    public function testInitParametricWritesAdapter(): void
    {
        $context = $this->context();

        (new Init())->execute(['--adapter', 'orcarouter', 'sk-abc', 'https://api.orcarouter.ai/v1'], $context);

        self::assertSame('orcarouter', $context->settings->get('adapter'));
        self::assertSame('sk-abc', $context->settings->get('apiKey'));
        self::assertSame('https://api.orcarouter.ai/v1', $context->settings->get('baseUrl'));
    }

    public function testInitParametricUnknownAdapterAborts(): void
    {
        $context = $this->context();

        $exit = (new Init())->execute(['--adapter', 'nope', 'sk-abc', 'https://x/v1'], $context);

        self::assertTrue($exit); // 不退出 REPL
        self::assertNull($context->settings->get('adapter'));
    }

    public function testInitParametricEmptyKeyAborts(): void
    {
        $context = $this->context();

        (new Init())->execute(['--adapter', 'orcarouter', '', 'https://api.orcarouter.ai/v1'], $context);

        self::assertNull($context->settings->get('adapter')); // 中止,不落任何写入
    }

    public function testInitRerunAdapterOnlyKeepsKeyUrl(): void
    {
        $context = $this->context(['adapter' => 'orcarouter', 'apiKey' => 'sk-old', 'baseUrl' => 'https://api.orcarouter.ai/v1']);

        // 只改 adapter(保留 key/url):--adapter id 两参 + 空参形式不覆盖已有值
        (new Init())->execute(['--adapter', 'generic', 'sk-old', ''], $context);

        self::assertSame('generic', $context->settings->get('adapter'));
        self::assertSame('sk-old', $context->settings->get('apiKey')); // 保留
        self::assertSame('https://api.orcarouter.ai/v1', $context->settings->get('baseUrl')); // 保留(空参不覆盖)
    }

    // ---------- 分派语义:settings.adapter → 适配器类(经 Init 描述表,同 Application::modelSource) ----------

    public function testDispatchBySettingsAdapter(): void
    {
        // 与 Application::modelSource 相同的查表逻辑(§8.2:显式选择,无 host 嗅探)
        foreach (['orcarouter' => OrcaRouterAdapter::class, 'generic' => GenericAdapter::class] as $id => $expected) {
            $resolved = null;
            foreach (Init::adapterClasses() as $class) {
                if ($class::id() === $id) {
                    $resolved = $class;
                    break;
                }
            }
            self::assertSame($expected, $resolved);
        }

        // 未选择/未知 id → 无适配器(settings.models 或 null 兜底,host 不参与)
        $resolved = null;
        foreach (Init::adapterClasses() as $class) {
            if ($class::id() === 'nope') {
                $resolved = $class;
            }
        }
        self::assertNull($resolved);
    }
}
