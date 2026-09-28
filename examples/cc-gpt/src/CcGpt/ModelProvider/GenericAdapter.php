<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

use Ws\Http\NanoGpt\ModelInfo;

/**
 * 通用适配器(design/21 §8.2,实现实例):任意 OpenAI 兼容服务,/models 全量不过滤。
 */
final class GenericAdapter extends AbstractServiceAdapter
{
    /** @var \Ws\Http\Plugin\OpenAI\Client */
    private $client;

    public function __construct(\Ws\Http\Plugin\OpenAI\Client $client)
    {
        $this->client = $client;
    }

    protected function fetch(): iterable
    {
        $body = $this->client->models()->list()->body;
        if (!\is_object($body) || !isset($body->data) || !\is_array($body->data)) {
            return [];
        }

        return $body->data;
    }

    /**
     * @param object|array<string, mixed> $raw
     */
    protected function map($raw): ?ModelInfo
    {
        $id = (string) ($raw->id ?? '');
        if ($id === '') {
            return null;
        }

        return new ModelInfo(
            $id,
            (string) ($raw->name ?? $id),
            (string) ($raw->pricing ?? ''),
            null,
            null,
            ModelInfo::SRC_API
        );
    }

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
