<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Support\ResultSet;

/**
 * 场景执行报告(design/16 §3.2):步骤序列 + 摘要 + 终态变量;toArray 为 JSON 报告契约。
 */
final class Report
{
    /** @var string */
    public $scenarioId;

    /** @var string */
    public $scenarioName;

    /** @var int 启动时间(unix ts) */
    public $startedAt;

    /** @var StepResult[] 按执行顺序(含 skipped) */
    private $steps = [];

    /** @var array<string, mixed> 终态变量快照(脱敏后) */
    private $variables = [];

    /** @var float 总耗时(ms) */
    private $totalDurationMs = 0.0;

    public function __construct(string $scenarioId, string $scenarioName)
    {
        $this->scenarioId = $scenarioId;
        $this->scenarioName = $scenarioName;
        $this->startedAt = time();
    }

    public function add(StepResult $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function finalize(array $variables, float $totalDurationMs): void
    {
        $this->variables = $variables;
        $this->totalDurationMs = $totalDurationMs;
    }

    /**
     * @return StepResult[]
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function passedCount(): int
    {
        return $this->countBy(StepResult::STATUS_PASSED);
    }

    public function failedCount(): int
    {
        return $this->countBy(StepResult::STATUS_FAILED);
    }

    public function skippedCount(): int
    {
        return $this->countBy(StepResult::STATUS_SKIPPED);
    }

    public function isSuccess(): bool
    {
        return $this->failedCount() === 0;
    }

    public function totalDurationMs(): float
    {
        return $this->totalDurationMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * JSON 报告契约(design/16 §3.2)。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stepArrays = [];
        foreach ($this->steps as $step) {
            $stepArrays[] = $step->toArray();
        }

        return [
            'scenario'   => ['id' => $this->scenarioId, 'name' => $this->scenarioName],
            'startedAt'  => $this->startedAt,
            'durationMs' => $this->totalDurationMs,
            'summary'    => [
                'passed'  => $this->passedCount(),
                'failed'  => $this->failedCount(),
                'skipped' => $this->skippedCount(),
                'success' => $this->isSuccess(),
            ],
            'variables'  => $this->variables,
            'steps'      => $stepArrays,
        ];
    }

    private function countBy(string $status): int
    {
        $count = 0;
        foreach ($this->steps as $step) {
            if ($step->status === $status) {
                $count++;
            }
        }

        return $count;
    }
}
