<?php

declare(strict_types=1);

namespace Ws\Http\Expression;

/**
 * 求值结果:节点集合(design/13 §3.1)。
 *
 * 空集不是错误:是否算失败由调用方语义决定(断言 → fail;提取 → onMissing 策略)。
 */
final class EvaluationResult
{
    /** @var mixed[] */
    private $nodes;

    /**
     * @param mixed[] $nodes
     */
    public function __construct(array $nodes)
    {
        $this->nodes = array_values($nodes);
    }

    /**
     * 所有匹配节点。
     *
     * @return mixed[]
     */
    public function all(): array
    {
        return $this->nodes;
    }

    /** 首个匹配;空集返回 null。 */
    public function first()
    {
        return $this->nodes[0] ?? null;
    }

    public function count(): int
    {
        return \count($this->nodes);
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }
}
