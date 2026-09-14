<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 提取结果收集(design/15 §6):written/warnings/failures 三桶,不抛异常。
 */
final class ExtractionResult
{
    /** @var array<int, array{var: string, value: mixed}> 成功写入 */
    public $written = [];

    /** @var string[] skip/default 事件 */
    public $warnings = [];

    /** @var array<int, array{var: string, message: string}> fail 事件 */
    public $failures = [];
}
