<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 延时步骤(design/14 §2.2):duration 已归一为秒(float)。
 */
final class DelayStep implements Step
{
    /** @var string */
    private $id;

    /** @var string */
    private $name;

    /** @var float 秒 */
    private $duration;

    public function __construct(string $id, string $name, float $duration)
    {
        $this->id = $id;
        $this->name = $name;
        $this->duration = $duration;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return 'delay';
    }

    public function duration(): float
    {
        return $this->duration;
    }
}
