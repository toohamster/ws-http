<?php

/**
 * 示例 3:场景脚本自动化引擎(functional 层完整形态)
 *
 * 演示:
 * 1. v2 场景脚本(变量/提取/断言/延时)的结构;
 * 2. CLI 跑场景 + JSON 报告输出;
 * 3. 编程式调用 Runner(嵌入你自己的 PHP 应用)。
 *
 * 运行:
 *   /usr/local/bin/php74 examples/scenario/run.php
 */

use Ws\Http\Automated\RequestFactory;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\ScenarioParser;

require __DIR__ . '/../bootstrap.php';

// ---------- 1. 用场景对象写一个"链式调用"脚本 ----------
// 场景:本地模拟两步 —— 由于示例不能依赖外部服务,这里直接构造
// 与 tests/fixtures/scenarios/booking-flow.json 相同的结构讲解字段。
$scriptPath = __DIR__ . '/scenario.json';

echo "场景脚本({$scriptPath}):\n";
echo substr((string) file_get_contents($scriptPath), 0, 400), "...\n\n";

// ---------- 2. 解析(加载期校验,错误含 JSON Pointer) ----------
$scenario = (new ScenarioParser())->parseFile($scriptPath);

printf("解析成功:id=%s, steps=%d, variables=%d\n\n",
    $scenario->id,
    count($scenario->steps),
    count($scenario->variables)
);

// ---------- 3. 执行:编程式 Runner 调用 ----------
$report = (new Runner(new RequestFactory()))->run($scenario);

printf("执行:%d passed / %d failed / %d skipped (%.0fms)\n",
    $report->passedCount(),
    $report->failedCount(),
    $report->skippedCount(),
    $report->totalDurationMs()
);

foreach ($report->steps() as $step) {
    printf("  [%s] %s (%s)%s\n",
        $step->status,
        $step->stepId,
        $step->statusCode !== null ? 'HTTP ' . $step->statusCode : $step->stepType,
        $step->failReason !== null ? ' ← ' . strtok((string) $step->failReason, "\n") : ''
    );
}

// ---------- 4. 终态变量(下游系统可直接消费) ----------
echo "\n终态变量:\n";
foreach ($report->variables() as $name => $value) {
    printf("  %-12s = %s\n", $name, is_scalar($value) ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE));
}

// ---------- 5. 提示:CLI 形态 ----------
echo "\nCLI 等价命令:\n";
echo "  /usr/local/bin/php74 bin/ws-http run {$scriptPath} --format=text\n";
echo "  /usr/local/bin/php74 bin/ws-http run {$scriptPath} --format=json --report-out=report.json --var city=440100\n";

echo "\n示例完成。\n";
