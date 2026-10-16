<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

/**
 * 静态适配器(design/21 §8.2;design/28 D3 修订):手写档案(settings.models;
 * 百炼类/自建场景)不走 Transport——手写条目直接 new ModelInfo
 * (允许携带 contextWindow/maxOutputTokens,provenance = manual)。
 */
final class StaticAdapter implements ModelSourceInterface
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

    /**
     * @return array<int, \Ws\Http\NanoGpt\ModelInfo>
     */
    public function models(): array
    {
        $out = [];
        foreach ($this->models as $raw) {
            $id = (string) ($raw['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $out[] = new \Ws\Http\NanoGpt\ModelInfo(
                $id,
                (string) ($raw['name'] ?? $id),
                (string) ($raw['pricing'] ?? ''),
                isset($raw['contextWindow']) && $raw['contextWindow'] !== null ? (int) $raw['contextWindow'] : null,
                isset($raw['maxOutputTokens']) && $raw['maxOutputTokens'] !== null ? (int) $raw['maxOutputTokens'] : null,
                \Ws\Http\NanoGpt\ModelInfo::SRC_MANUAL
            );
        }

        return $out;
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
