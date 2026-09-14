<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 步骤契约(design/14 §3):HttpStep | DelayStep 的公共面。
 */
interface Step
{
    public function id(): string;

    public function name(): string;

    /** @return string http|delay */
    public function type(): string;
}
