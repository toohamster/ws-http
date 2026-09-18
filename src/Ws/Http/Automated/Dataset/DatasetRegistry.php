<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

/**
 * 数据源注册表(design/23 §2.2):loader 名 → 实现;错误码 420/421。
 */
final class DatasetRegistry
{
    /** @var array<string, DatasetLoaderInterface> */
    private $loaders = [];

    /**
     * @throws \Ws\Http\Automated\AutomatedException 420 重名
     */
    public function register(string $name, DatasetLoaderInterface $loader): void
    {
        if (isset($this->loaders[$name])) {
            throw new \Ws\Http\Automated\AutomatedException(
                sprintf('Dataset loader "%s" already registered', $name),
                420
            );
        }
        $this->loaders[$name] = $loader;
    }

    public function has(string $name): bool
    {
        return isset($this->loaders[$name]);
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<int, array<string, mixed>>
     * @throws \Ws\Http\Automated\AutomatedException 421 未知名
     */
    public function load(string $name, array $spec): array
    {
        if (!isset($this->loaders[$name])) {
            throw new \Ws\Http\Automated\AutomatedException(
                sprintf('Unknown dataset loader "%s"', $name),
                421
            );
        }

        return $this->loaders[$name]->load($spec);
    }
}
