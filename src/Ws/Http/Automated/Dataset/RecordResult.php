<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

use Ws\Http\Automated\Report;

/**
 * 单条记录的执行结果(design/23 §4):批次报告的最小单元。
 */
final class RecordResult
{
    /** @var Report 该轮完整场景报告 */
    public $report;

    /** @var array{index: int, data: array<string, mixed>}|null recordVar 形态(脚本内可引用当前记录) */
    public $recordVar;

    /**
     * @param array{index: int, data: array<string, mixed>}|null $recordVar
     */
    public function __construct(Report $report, ?array $recordVar = null)
    {
        $this->report = $report;
        $this->recordVar = $recordVar;
    }
}
