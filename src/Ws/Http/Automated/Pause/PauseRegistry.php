<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Pause;

/**
 * pause 取值策略注册表(design/22 §3.3)。
 *
 * - 注册/查用;'poll:<name>' 前缀由本表特殊解析:包装 name 对应的
 *   ExtractorInterface(design/15 注册表)为 PollSource;
 * - 装配期(Runner 构造)注入;未知名在装配/校验期即报 407。
 */
final class PauseRegistry
{
    /** @var array<string, ValueSource> */
    private $sources = [];

    /** @var array<string, \Ws\Http\Contract\ExtractorInterface> poll 用的提取器注册表(名 → 提取器) */
    private $pollExtractors;

    /** @var array<string, mixed> poll 缺省参数(interval/timeout 秒) */
    private $pollDefaults;

    /**
     * @param array<int, ValueSource> $sources
     * @param array<string, \Ws\Http\Contract\ExtractorInterface> $pollExtractors
     * @param array{interval?: float, timeout?: float} $pollDefaults
     */
    public function __construct(array $sources = [], array $pollExtractors = [], array $pollDefaults = [])
    {
        foreach ($sources as $source) {
            $this->register($source);
        }
        $this->pollExtractors = $pollExtractors;
        $this->pollDefaults = array_merge(['interval' => 5.0, 'timeout' => 300.0], $pollDefaults);
    }

    /**
     * @throws \Ws\Http\Automated\AutomatedException 406 重名
     */
    public function register(ValueSource $source): void
    {
        $id = $source::id();
        if (isset($this->sources[$id])) {
            throw new \Ws\Http\Automated\AutomatedException(
                sprintf('Pause source "%s" already registered', $id),
                406
            );
        }
        $this->sources[$id] = $source;
    }

    public function has(string $id): bool
    {
        if (strpos($id, 'poll:') === 0) {
            return isset($this->pollExtractors[substr($id, 5)]);
        }

        return isset($this->sources[$id]);
    }

    /**
     * @throws \Ws\Http\Automated\AutomatedException 407 未知名
     */
    public function get(string $id): ValueSource
    {
        if (strpos($id, 'poll:') === 0) {
            $name = substr($id, 5);
            if (!isset($this->pollExtractors[$name])) {
                throw new \Ws\Http\Automated\AutomatedException(
                    sprintf('Unknown pause source "%s" (poll extractor "%s" not registered)', $id, $name),
                    407
                );
            }

            return new PollSource($this->pollExtractors[$name], $this->pollDefaults);
        }

        if (!isset($this->sources[$id])) {
            throw new \Ws\Http\Automated\AutomatedException(
                sprintf('Unknown pause source "%s"', $id),
                407
            );
        }

        return $this->sources[$id];
    }

    /**
     * @return string[]
     */
    public function ids(): array
    {
        return array_keys($this->sources);
    }
}
