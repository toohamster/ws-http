<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;

/**
 * 数据驱动执行器(design/23 §3):Runner 的外层迭代包装,不改 Runner 内部。
 *
 * - 每条记录注入新 VariableScope(记录字段即变量),完整跑一遍场景;
 * - failFast 收窄到单轮:批次永远跑完全部记录;
 * - 记录字段可被场景内 extract 覆盖(记录字段初始注入,extract 优先级更高)。
 */
final class DatasetRunner
{
    /** @var Runner */
    private $runner;

    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    /**
     * @param array<int, array<string, mixed>> $records 记录列表(同构 object,校验在解析层)
     * @param bool $withRecordVar true 时注入 _recordIndex/_recordData 变量(脚本可引用当前记录)
     */
    public function run(Scenario $scenario, array $records, bool $withRecordVar = false): BatchReport
    {
        $startedAt = microtime(true);

        $batch = new BatchReport((string) ($scenario->settings['iterate'] ?? '_cli'));

        foreach ($records as $index => $record) {
            $recordScenario = $this->scopedScenario($scenario, $record, (int) $index, $withRecordVar);
            $report = $this->runner->run($recordScenario);
            // recordVar 始终记录(data 供 BatchReport.failures[].record 排查用;withRecordVar 仅控制变量注入)
            $batch->add(new RecordResult($report, ['index' => (int) $index, 'data' => $record]));
        }

        $batch->finalize((microtime(true) - $startedAt) * 1000);

        return $batch;
    }

    /**
     * 每轮克隆场景并把记录字段并入初始变量(记录字段在前,variables 声明同名的以 variables 为准——
     * 与"extract 可覆盖记录字段"一致的优先级:显式声明 > 记录注入)。
     *
     * @param array<string, mixed> $record
     */
    private function scopedScenario(Scenario $scenario, array $record, int $index, bool $withRecordVar): Scenario
    {
        $scoped = clone $scenario;

        $variables = [];
        foreach ($record as $key => $value) {
            $variables[] = ['name' => (string) $key, 'value' => $value];
        }

        // 场景 variables 声明优先(显式声明 > 记录注入)
        foreach ($scenario->variables as $declared) {
            foreach ($variables as $i => $v) {
                if ($v['name'] === $declared['name']) {
                    unset($variables[$i]);
                }
            }
            $variables[] = $declared;
        }

        if ($withRecordVar) {
            // design/15 占位符只支持 ${name}(无嵌套路径语法),recordVar 拆为扁平标量变量
            $variables[] = ['name' => '_recordIndex', 'value' => $index];
            $variables[] = ['name' => '_recordData', 'value' => $record];
        }

        $scoped->variables = array_values($variables);

        return $scoped;
    }
}
