<?php

declare(strict_types=1);

namespace Ws\Http\Support;

/**
 * 通用结果容器(S7.5):断言结果、提取结果、步骤结果、报告的统一消费形态。
 *
 * - 只做容器与过滤,不理解业务字段;消费方(CLI/报告)经 toArray() 渲染;
 * - 项必须是带 toArray() 的结果对象(或经 filter/passed/failed 语义使用)。
 *
 * @implements \IteratorAggregate<int, object>
 */
final class ResultSet implements \IteratorAggregate, \Countable
{
    /** @var object[] */
    private $items;

    /**
     * @param object[] $items
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    public function add(object $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return object[]
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * 过滤为新集合(原集合不变)。
     *
     * @param callable(object): bool $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->items, $predicate)));
    }

    /**
     * 有 passed 属性且为 true 的项(结果对象通用语义)。
     */
    public function passed(): self
    {
        return $this->filter(static fn (object $item): bool => ($item->passed ?? null) === true);
    }

    /**
     * 有 passed 属性且非 true 的项。
     */
    public function failed(): self
    {
        return $this->filter(static fn (object $item): bool => ($item->passed ?? null) !== true);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * 逐项 toArray() 形态(CLI/报告/CI 渲染入口)。
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->items as $item) {
            if (!\is_callable([$item, 'toArray'])) {
                throw new \InvalidArgumentException(sprintf(
                    'ResultSet item %s must provide toArray()',
                    \get_class($item)
                ));
            }
            $out[] = $item->toArray();
        }

        return $out;
    }

    /**
     * @return \ArrayIterator<int, object>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
