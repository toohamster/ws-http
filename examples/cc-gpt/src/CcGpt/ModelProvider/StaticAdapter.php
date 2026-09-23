<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

/**
 * 静态适配器(design/21 §8.2):手写档案(settings.models 数组;百炼类/自建场景)。
 */
final class StaticAdapter extends AbstractServiceAdapter
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

    protected function fetch(): array
    {
        return $this->models;
    }

    /**
     * @param array<int, array{id: string, name: string, pricing: string}> $raw
     * @return array<int, array{id: string, name: string, pricing: string}>
     */
    protected function normalize(array $raw): array
    {
        return $raw;
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
