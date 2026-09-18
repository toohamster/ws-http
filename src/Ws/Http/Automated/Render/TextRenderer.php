<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Render;

use Ws\Http\Automated\Dataset\BatchReport;
use Ws\Http\Automated\Report;
use Ws\Http\Automated\StepResult;

/**
 * 人类可读文本渲染(design/16 §5.1):由 bin/ws-http 的私有渲染逻辑迁出,场景与批次两形态共用。
 */
final class TextRenderer implements ReportRendererInterface
{
    /**
     * @param Report|BatchReport $report
     */
    public function render($report): string
    {
        if ($report instanceof BatchReport) {
            return $this->renderBatch($report);
        }

        \assert($report instanceof Report);

        return $this->renderScenario($report);
    }

    private function renderScenario(Report $report): string
    {
        $lines = [];
        $lines[] = sprintf('Scenario %s (%s)', $report->scenarioName, $report->scenarioId);

        foreach ($report->steps() as $step) {
            $mark = $this->mark($step->status);
            $line = sprintf(
                '  %s %-10s %-28s %s  %sms',
                $mark,
                $step->stepId,
                mb_substr($step->stepName, 0, 28),
                $step->statusCode !== null ? (string) $step->statusCode : ($step->status === StepResult::STATUS_SKIPPED ? 'skipped' : ''),
                $step->durationMs !== null ? number_format((float) $step->durationMs, 0) : '-'
            );
            $lines[] = $line;

            if ($step->status === StepResult::STATUS_FAILED) {
                foreach (explode("\n", (string) $step->failReason) as $reasonLine) {
                    $lines[] = sprintf('       %s', $reasonLine);
                }
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Summary: %d passed, %d failed, %d skipped (%sms)',
            $report->passedCount(),
            $report->failedCount(),
            $report->skippedCount(),
            number_format($report->totalDurationMs(), 0)
        );

        return implode("\n", $lines) . "\n";
    }

    private function renderBatch(BatchReport $batch): string
    {
        $lines = [];
        $lines[] = sprintf('Batch dataset: %s', $batch->dataset);

        foreach ($batch->records() as $i => $record) {
            $report = $record->report;
            $mark = $report->isSuccess() ? '✓' : '✗';
            $lines[] = sprintf(
                '  %s #%d  %d passed, %d failed, %d skipped  %sms',
                $mark,
                $i,
                $report->passedCount(),
                $report->failedCount(),
                $report->skippedCount(),
                number_format($report->totalDurationMs(), 0)
            );
            if (!$report->isSuccess()) {
                foreach ($report->steps() as $step) {
                    if ($step->status === StepResult::STATUS_FAILED) {
                        $lines[] = sprintf('       step %s: %s', $step->stepId, (string) $step->failReason);
                    }
                }
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Batch summary: %d/%d passed, %d failed (%s) in %sms',
            $batch->passed(),
            $batch->total(),
            $batch->failed(),
            implode(', ', $batch->failedRecordIndexes()),
            number_format($batch->totalDurationMs(), 0)
        );

        return implode("\n", $lines) . "\n";
    }

    private function mark(string $status): string
    {
        switch ($status) {
            case StepResult::STATUS_PASSED:
                return '✓';
            case StepResult::STATUS_FAILED:
                return '✗';
            default:
                return '-';
        }
    }
}
