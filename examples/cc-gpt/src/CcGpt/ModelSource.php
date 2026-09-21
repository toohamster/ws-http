<?php

declare(strict_types=1);

namespace CcGpt;

use Ws\Http\Plugin\OpenAI\Client;

/**
 * orcarouter 形态(design/21 §8.1):models()->list() → 过滤含 "free" 的模型。
 */
final class OrcaRouterModelSource implements ModelSourceInterface
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function models(): array
    {
        try {
            $response = $this->client->models()->list();
        } catch (\Throwable $e) {
            return [];
        }

        $body = $response->body;
        if (!\is_object($body) || !isset($body->data) || !\is_array($body->data)) {
            return [];
        }

        $out = [];
        foreach ($body->data as $model) {
            $id = (string) ($model->id ?? '');
            if ($id === '' || stripos($id, 'free') === false) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'name'     => (string) ($model->name ?? $id),
                'pricing'  => (string) ($model->pricing ?? ''),
            ];
        }

        return $out;
    }
}

/**
 * 静态形态(design/21 §8.1):config/.settings 手写列表(百炼类手动指定场景)。
 */
final class StaticModelSource implements ModelSourceInterface
{
    /** @var array<int, array{id: string, name: string, pricing: string}> */
    private $models;

    /**
     * @param array<int, array{id: string, name: string, pricing: string}> $models
     */
    public function __construct(array $models)
    {
        $this->models = $models;
    }

    public function models(): array
    {
        return $this->models;
    }
}
