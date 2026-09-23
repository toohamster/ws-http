<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

/**
 * orcarouter 适配器(design/21 §8.2):只填服务差异——/models 调用与 free 过滤。
 */
final class OrcaRouterAdapter extends AbstractServiceAdapter
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
            if ($id === '' || stripos($id, 'free') === false) {
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
