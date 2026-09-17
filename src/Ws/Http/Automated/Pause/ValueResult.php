<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Pause;

/**
 * 取值结果(design/22 §3):ValueSource::fetch 的返回值对象。
 *
 * GOT      = 拿到值(value 生效);
 * PENDING  = 暂未就绪(仅可轮询策略返回;Runner/外层循环按此重试);
 * MISSING  = 确认没有(立即失败,不再重试)。
 */
final class ValueResult
{
    public const GOT = 'got';
    public const PENDING = 'pending';
    public const MISSING = 'missing';

    /** @var string */
    private $status;

    /** @var mixed */
    private $value;

    /** @var string|null missing 时的原因(进 StepResult failReason) */
    private $reason;

    /**
     * @param mixed $value
     */
    private function __construct(string $status, $value = null, ?string $reason = null)
    {
        $this->status = $status;
        $this->value = $value;
        $this->reason = $reason;
    }

    /**
     * @param mixed $value
     */
    public static function got($value): self
    {
        return new self(self::GOT, $value);
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function missing(string $reason): self
    {
        return new self(self::MISSING, null, $reason);
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return mixed
     */
    public function value()
    {
        return $this->value;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
