<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

use Ws\Http\Automated\Report;

/**
 * 批次报告(design/23 §4):组维度汇总 + 失败记录明细;toArray 为 JSON 契约(kind=batch)。
 */
final class BatchReport
{
    /** @var string 迭代的数据集名 */
    public $dataset;

    /** @var int 启动时间(unix ts) */
    public $startedAt;

    /** @var array<int, RecordResult> 按记录顺序(每轮完整 Report 挂载) */
    private $records = [];

    /** @var float 总耗时(ms) */
    private $totalDurationMs = 0.0;

    public function __construct(string $dataset)
    {
        $this->dataset = $dataset;
        $this->startedAt = time();
    }

    public function add(RecordResult $record): void
    {
        $this->records[] = $record;
    }

    public function finalize(float $totalDurationMs): void
    {
        $this->totalDurationMs = $totalDurationMs;
    }

    /**
     * @return RecordResult[]
     */
    public function records(): array
    {
        return $this->records;
    }

    public function total(): int
    {
        return \count($this->records);
    }

    public function passed(): int
    {
        return $this->countBy(false);
    }

    public function failed(): int
    {
        return $this->countBy(true);
    }

    public function isSuccess(): bool
    {
        return $this->failed() === 0;
    }

    /**
     * @return int[]
     */
    public function failedRecordIndexes(): array
    {
        $indexes = [];
        foreach ($this->records as $i => $record) {
            if (!$record->report->isSuccess()) {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    public function totalDurationMs(): float
    {
        return $this->totalDurationMs;
    }

    /**
     * JSON 批次报告契约(design/23 §4)。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $failures = [];
        $allRecords = [];
        foreach ($this->records as $i => $record) {
            $recordArray = [
                'index'      => $i,
                'report'     => $record->report->toArray(),
            ];
            if ($record->recordVar !== null) {
                $recordArray['data'] = $record->recordVar['data'];
            }
            $allRecords[] = $recordArray;

            if (!$record->report->isSuccess()) {
                $failedStep = '';
                foreach ($record->report->steps() as $step) {
                    if ($step->status === 'failed') {
                        $failedStep = $step->stepId;
                        break;
                    }
                }
                $failures[] = [
                    'index'      => $i,
                    'record'     => $record->recordVar !== null ? $record->recordVar['data'] : null,
                    'report'     => $record->report->toArray(),
                    'failedStep' => $failedStep,
                ];
            }
        }

        return [
            'kind'            => 'batch',
            'dataset'         => $this->dataset,
            'startedAt'       => $this->startedAt,
            'durationMs'      => $this->totalDurationMs,
            'total'           => $this->total(),
            'passed'          => $this->passed(),
            'failed'          => $this->failed(),
            'failedRecords'   => $this->failedRecordIndexes(),
            'failures'        => $failures,
            'records'         => $allRecords,
        ];
    }

    private function countBy(bool $failed): int
    {
        $count = 0;
        foreach ($this->records as $record) {
            if ($record->report->isSuccess() === !$failed) {
                $count++;
            }
        }

        return $count;
    }
}
