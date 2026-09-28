<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

use Ws\Http\NanoGpt\ModelInfo;

/**
 * 静态适配器(design/21 §8.2,实现实例):手写档案(settings.models;百炼类/自建场景)。
 *
 * 手写条目直接 new ModelInfo(允许携带 contextWindow/maxOutputTokens,provenance = manual)。
 */
final class StaticAdapter extends AbstractServiceAdapter
{
    /** @var array<int, array<string, mixed>> */
    private $models;

    /**
     * @param array<int, array<string, mixed>> $models 手写档案:{id, name?, pricing?, contextWindow?, maxOutputTokens?}
     */
    public function __construct(array $models)
    {
        $this->models = $models;
    }

    protected function fetch(): iterable
    {
        return $this->models;
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function map($raw): ?ModelInfo
    {
        $id = (string) ($raw['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return new ModelInfo(
            $id,
            (string) ($raw['name'] ?? $id),
            (string) ($raw['pricing'] ?? ''),
            isset($raw['contextWindow']) && $raw['contextWindow'] !== null ? (int) $raw['contextWindow'] : null,
            isset($raw['maxOutputTokens']) && $raw['maxOutputTokens'] !== null ? (int) $raw['maxOutputTokens'] : null,
            ModelInfo::SRC_MANUAL
        );
    }

    public static function id(): string
    {
        return 'static';
    }

    public static function label(): string
    {
        return 'static (hand-written settings.models; not in /init menu)';
    }

    /**
     * 静态档案无交互输入项(手配 settings.models);不进 /init 菜单,自报契约仅为完整性。
     *
     * @return array<int, array{key: string, prompt: string}>
     */
    public static function prompts(): array
    {
        return [];
    }
}
