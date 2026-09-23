<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

/**
 * 通用适配器(design/21 §8.2):任意 OpenAI 兼容服务,/models 全量列表不过滤。
 */
final class GenericAdapter extends AbstractServiceAdapter
{
    /** @var \Ws\Http\Plugin\OpenAI\Client */
    private $client;

    public function __construct(\Ws\Http\Plugin\OpenAI\Client $client)
    {
        $this->client = $client;
    }

    protected function fetch(): array
    {
        $body = $this->client->models()->list()->body;
        if (!\is_object($body) || !isset($body->data) || !\is_array($body->data)) {
            return [];
        }

        return $body->data;
    }

    /**
     * @param array<int, object|array<string, mixed>> $raw
     * @return array<int, array{id: string, name: string, pricing: string}>
     */
    protected function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $model) {
            $id = (string) ($model->id ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id'      => $id,
                'name'    => (string) ($model->name ?? $id),
                'pricing' => (string) ($model->pricing ?? ''),
            ];
        }

        return $out;
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
