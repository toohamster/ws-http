<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\VendorCatalog;

/**
 * design/28 §2(V1):VendorCatalog——已知厂商档案表(档案非工厂)。
 *
 * 钉住:8 家初装齐全;get 已知/未知;protocol 字段语义;表条目形状。
 */
final class VendorCatalogTest extends TestCase
{
    public function testKnownContainsEightVendors(): void
    {
        $catalog = VendorCatalog::known();

        self::assertSame(
            ['openai', 'deepseek', 'qwen', 'kimi', 'moonshot', 'minimax', 'claude', 'orcarouter'],
            array_keys($catalog)
        );
    }

    /**
     * @dataProvider vendorProvider
     */
    public function testGetKnownVendor(string $id, string $expectedBaseUrl, string $expectedProtocol): void
    {
        $vendor = VendorCatalog::get($id);

        self::assertNotNull($vendor);
        self::assertArrayHasKey('name', $vendor);
        self::assertSame($expectedBaseUrl, $vendor['baseUrl']);
        self::assertSame($expectedProtocol, $vendor['protocol']);
        self::assertArrayHasKey('defaultModel', $vendor);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public function vendorProvider(): array
    {
        return [
            'openai'    => ['openai', 'https://api.openai.com/v1', 'openai-compatible'],
            'deepseek'  => ['deepseek', 'https://api.deepseek.com/v1', 'openai-compatible'],
            'qwen'      => ['qwen', 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'openai-compatible'],
            'kimi'      => ['kimi', 'https://api.kimi.com/coding/v1', 'openai-compatible'],
            'moonshot'  => ['moonshot', 'https://api.moonshot.cn/v1', 'openai-compatible'],
            'minimax'   => ['minimax', 'https://api.minimax.chat/v1', 'openai-compatible'],
            'claude'    => ['claude', 'https://api.anthropic.com/v1', 'anthropic'],
            'orcarouter' => ['orcarouter', 'https://api.orcarouter.ai/v1', 'openai-compatible'],
        ];
    }

    public function testGetUnknownReturnsNull(): void
    {
        self::assertNull(VendorCatalog::get('no-such-vendor'));
        self::assertNull(VendorCatalog::get(''));
    }

    public function testOpenAiCompatibleMajority(): void
    {
        // 8 家中 7 家 OpenAI 兼容(换 baseUrl 即用),仅 claude 是协议级不同
        $protocols = array_column(VendorCatalog::known(), 'protocol');

        self::assertSame(7, count(array_keys($protocols, 'openai-compatible', true)));
        self::assertSame(1, count(array_keys($protocols, 'anthropic', true)));
    }
}
