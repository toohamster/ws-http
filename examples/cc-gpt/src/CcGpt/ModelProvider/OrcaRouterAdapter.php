<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

use Ws\Http\NanoGpt\ModelInfo;

/**
 * orcarouter 适配器(design/21 §8.2,实现实例):set 字段映射 + free 过滤(map 返回 null)。
 *
 * S3 观察事实(2026-09-23 真实 /models 响应):无结构化窗口字段;pricing 是对象
 * {"request": "..."};窗口信息只在 description 自由文本——不从文本猜,走探测/手动级联。
 */
final class OrcaRouterAdapter extends AbstractServiceAdapter
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
        if ($id === '' || stripos($id, 'free') === false) {
            return null; // free 过滤(实例行为选择)
        }

        // S3 观察:pricing 是对象 {request: "..."},取其 request 值
        $pricing = '';
        if (isset($raw->pricing) && \is_object($raw->pricing)) {
            $pricing = (string) ($raw->pricing->request ?? '');
        }

        return new ModelInfo(
            $id,
            (string) ($raw->name ?? $id),
            $pricing,
            null,  // 无结构化窗口字段(S3 观察)——能力参数走选择后探测/手动级联
            null,
            ModelInfo::SRC_API
        );
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
