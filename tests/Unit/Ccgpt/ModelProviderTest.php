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
use Ws\Http\NanoGpt\ModelInfo;

/**
 * design/21 §8.2:ModelProvider 三层适配器(ModelInfo 属性模型)+ /init 显式选择分派。
 *
 * 仅围绕项目给定的 orcarouter 配置语义(S3 观察事实:无结构化窗口字段、pricing 为对象);
 * 真实链路由 examples/cc-gpt/bin/smoke 冒烟覆盖,不在此虚构服务。
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
        $orcaKeys = array_map(static fn (array $p): string => $p['key'], OrcaRouterAdapter::prompts());
        self::assertSame(['apiKey', 'baseUrl'], $orcaKeys);

        self::assertSame([], StaticAdapter::prompts());
    }

    // ---------- StaticAdapter(手写档案直接 set 进 ModelInfo,provenance = manual) ----------

    public function testStaticAdapterMapsToModelInfo(): void
    {
        $adapter = new StaticAdapter([
            ['id' => 'qwen-max', 'name' => 'Qwen Max', 'pricing' => '0.002', 'contextWindow' => 32768],
            ['id' => 'qwen-turbo'],   // 最简条目:能力缺省
            ['id' => ''],             // 非法条目被过滤
        ]);

        $models = $adapter->models();
        self::assertCount(2, $models);

        self::assertSame('qwen-max', $models[0]->id());
        self::assertSame('Qwen Max', $models[0]->name());
        self::assertSame('0.002', $models[0]->pricing());
        self::assertSame(32768, $models[0]->contextWindow());
        self::assertSame(ModelInfo::SRC_MANUAL, $models[0]->provenance());

        self::assertSame('qwen-turbo', $models[1]->id());
        self::assertNull($models[1]->contextWindow());
        self::assertSame('qwen-turbo', $models[1]->name());
    }

    // ---------- 模板层(通用 Provider):骨架 map-set + 容错 ----------

    public function testTemplateFiltersNullMapsAndToleratesFetchFailure(): void
    {
        // OrcaRouterAdapter:fetch 失败(unit 未配/网络不可达)→ 容错 → 空
        $adapter = new OrcaRouterAdapter(
            new \Ws\Http\Plugin\OpenAI\Client('sk-test', null, 'https://unit.invalid/v1')
        );

        self::assertSame([], $adapter->models());
    }

    // ---------- 分派语义:settings.adapter → 适配器类(经 Init 描述表,同 Application::modelSource) ----------

    public function testDispatchBySettingsAdapter(): void
    {
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

        $resolved = null;
        foreach (Init::adapterClasses() as $class) {
            if ($class::id() === 'nope') {
                $resolved = $class;
            }
        }
        self::assertNull($resolved);
    }

    // ---------- /init 参数化形态(--adapter) ----------

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

        self::assertTrue($exit);
        self::assertNull($context->settings->get('adapter'));
    }

    public function testInitParametricEmptyKeyAborts(): void
    {
        $context = $this->context();

        (new Init())->execute(['--adapter', 'orcarouter', '', 'https://api.orcarouter.ai/v1'], $context);

        self::assertNull($context->settings->get('adapter'));
    }

    public function testInitRerunAdapterOnlyKeepsKeyUrl(): void
    {
        $context = $this->context(['adapter' => 'orcarouter', 'apiKey' => 'sk-old', 'baseUrl' => 'https://api.orcarouter.ai/v1']);

        (new Init())->execute(['--adapter', 'generic', 'sk-old', ''], $context);

        self::assertSame('generic', $context->settings->get('adapter'));
        self::assertSame('sk-old', $context->settings->get('apiKey'));
        self::assertSame('https://api.orcarouter.ai/v1', $context->settings->get('baseUrl'));
    }
}
