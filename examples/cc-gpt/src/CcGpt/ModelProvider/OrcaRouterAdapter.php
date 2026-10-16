<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

use Ws\Http\NanoGpt\Transport\OrcaRouterTransport;

/**
 * orcarouter 适配器(design/21 §8.2;design/28 D3 薄壳化):目录解析(free 过滤/
 * 字段归一)已下沉 OrcaRouterTransport::modelFromEntry——适配器只剩服务身份 + 委托。
 *
 * S3 观察事实(2026-09-23 真实 /models 响应)在 Transport 厂商类内处置。
 */
final class OrcaRouterAdapter extends AbstractServiceAdapter
{
    public static function id(): string
    {
        return 'orcarouter';
    }

    public static function label(): string
    {
        return 'orcarouter (free model filter)';
    }

    public static function prompts(): array
    {
        return [
            ['key' => 'apiKey', 'prompt' => 'API key'],
            ['key' => 'baseUrl', 'prompt' => 'Base URL', 'default' => 'https://api.orcarouter.ai/v1'],
        ];
    }
}
