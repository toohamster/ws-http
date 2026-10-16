<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

use Ws\Http\NanoGpt\Transport\OpenAiTransport;

/**
 * 通用适配器(design/21 §8.2;design/28 D3 薄壳化):任意 OpenAI 兼容服务,
 * 目录解析(全量不过滤)已下沉 OpenAiTransport 基类——适配器只剩服务身份 + 委托。
 */
final class GenericAdapter extends AbstractServiceAdapter
{
    public static function id(): string
    {
        return 'generic';
    }

    public static function label(): string
    {
        return 'generic (any OpenAI-compatible service)';
    }

    public static function prompts(): array
    {
        return [
            ['key' => 'apiKey', 'prompt' => 'API key'],
            ['key' => 'baseUrl', 'prompt' => 'Base URL', 'default' => 'https://api.openai.com/v1'],
        ];
    }
}
