<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * S1 工程基建验收:autoload 与运行环境自检。
 */
final class SkeletonTest extends TestCase
{
    public function testComposerAutoloadIsConfigured(): void
    {
        // src/Ws/Http 下尚无类,先验证 PHP 版本与扩展;
        // core 类落地后此处补充 autoload 冒烟断言(见 testPhp74Runtime)
        self::assertSame('7.4', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
    }

    public function testRequiredExtensionsAreLoaded(): void
    {
        self::assertTrue(extension_loaded('curl'), 'ext-curl is required');
        self::assertTrue(extension_loaded('json'), 'ext-json is required');
    }

    public function testPsr4AutoloadResolvesNamespaces(): void
    {
        // autoload-dev:tests 命名空间
        self::assertStringContainsString(
            'Ws\\Http\\Tests\\',
            implode(',', array_keys(require __DIR__ . '/../../../vendor/composer/autoload_psr4.php'))
        );
    }
}
