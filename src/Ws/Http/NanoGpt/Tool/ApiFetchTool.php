<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\Automated\Report;
use Ws\Http\Automated\RequestFactory;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Automated\ScenarioOutput;
use Ws\Http\Automated\ScenarioParser;
use Ws\Http\Automated\VariableScope;
use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Request;
use Ws\Http\Response;

/**
 * 数据提取(design/25 §4.5;design/27 O2 场景形态升级):双形态取数——
 *
 *   形态 A(单请求,首版):{ url, extract[] } → GET + JSONPath 紧凑结果集;
 *   形态 B(场景取数,design/27):{ scenario, vars?, outputs? } → 场景文件解析 →
 *     Runner 执行 → ScenarioOutput 提取声明输出 → JSON 结果集
 *     (多步流程的最终产物;变量覆盖经 vars 合并,签名收窄经 outputs 子集)。
 *
 * 场景形态安全面:场景文件只读;网络出口仍走本工具 allowHosts 白名单(场景内
 * URL 逐一过 assertHostAllowed——RequestFactory 注入本工具的 http,host 校验在
 * execute 内先于场景执行对场景全部 URL 前置完成)。
 */
final class ApiFetchTool extends HttpToolBase
{
    public function __construct(array $allowHosts, ?Request $http = null, int $maxBody = 2048, bool $allowPrivateHosts = false)
    {
        parent::__construct($allowHosts, $http, $maxBody, $allowPrivateHosts);
    }

    public function name(): string
    {
        return 'api_fetch';
    }

    public function description(): string
    {
        return 'Fetch data: mode A {url, extract[]} for a single GET + JSONPath; mode B {scenario, vars?, outputs?} to run a scenario file (multi-step flow) and get its declared outputs as a JSON result set.';
    }

    protected function httpMethod(): string
    {
        return 'GET';
    }

    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url'      => ['type' => 'string', 'description' => 'Mode A: absolute http(s) URL'],
                'extract'  => ['type' => 'array', 'description' => 'Mode A: JSONPath expressions (e.g. ["$.data.temp"]); omitted = raw body excerpt'],
                'scenario' => ['type' => 'string', 'description' => 'Mode B: absolute path to a scenario JSON file (outputs-declared = data function)'],
                'vars'     => ['type' => 'object', 'description' => 'Mode B: variable overrides merged into scenario variables'],
                'outputs'  => ['type' => 'array', 'description' => 'Mode B: optional subset of the scenario outputs to return (narrowing only)'],
            ],
        ];
    }

    /**
     * 输出契约(design/25 §4.5 评审钉死;design/27 O2 增场景形态):
     * - 形态 A:extract 空/缺省 → body 原样截断;有 → 紧凑结果集 {"items": {...}}(无匹配 "(no match)");
     * - 形态 B:成功 → "status: ok\n{结果集 JSON}"(键序 = outputs 声明序);
     *   场景失败 → "status: failed\n..."(含 failedStep,§5 回归点);解析/文件错 → "error: ..."。
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        if ($this->allowHosts === []) {
            return 'error: api_fetch is disabled (no allowed hosts configured)';
        }

        // 形态分派:scenario 优先(B 是 A 的超集演进;两参数同时给 = 配置歧义,B 优先并注明)
        if (isset($args['scenario'])) {
            return $this->runScenario($args);
        }

        return $this->runSingleRequest($args);
    }

    /**
     * 形态 A(单请求,首版逻辑原样)。
     *
     * @param array<string, mixed> $args
     */
    private function runSingleRequest(array $args): string
    {
        $url = (string) ($args['url'] ?? '');
        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return 'error: invalid url';
        }

        try {
            $this->assertHostAllowed($host);
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            return 'error: ' . $e->getMessage();
        }

        $extract = $args['extract'] ?? null;
        if (!\is_array($extract) || $extract === []) {
            return $this->send($url); // 退化形态
        }

        $http = $this->http ?? new Request();
        try {
            $response = $http->send($this->method, $url, null, []);
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }

        if ($response->body === false) {
            return sprintf('status: %d\nerror: response body is not valid JSON (extract requires JSON)', $response->code);
        }

        $items = [];
        $evaluator = new ExpressionEvaluator();
        foreach ($extract as $path) {
            $path = (string) $path;
            $evaluated = $evaluator->evaluate($response->body, $path);
            $items[$path] = $evaluated->isEmpty()
                ? '(no match)'
                : $evaluated->first();
        }

        return sprintf(
            "status: %d\n%s",
            $response->code,
            (string) json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * 形态 B(场景取数,design/27 §3):解析 → 白名单前置校验 → Runner → ScenarioOutput。
     *
     * @param array<string, mixed> $args
     */
    private function runScenario(array $args): string
    {
        $path = (string) $args['scenario'];
        if ($path === '') {
            return 'error: scenario path is empty';
        }

        $parser = new ScenarioParser();
        try {
            $scenario = $parser->parseFile($path);
        } catch (\Throwable $e) {
            return 'error: scenario parse failed: ' . $e->getMessage();
        }

        if ($scenario->outputs === []) {
            return 'error: scenario declares no outputs (add an outputs array to use it as a data function)';
        }

        // 白名单前置:场景内全部 http URL 逐一过本工具授权面(执行前拒绝,不发请求)
        foreach ($scenario->steps as $step) {
            if ($step instanceof \Ws\Http\Automated\HttpStep) {
                $host = parse_url($step->url(), PHP_URL_HOST);
                if (!\is_string($host) || $host === '') {
                    return sprintf('error: scenario step "%s" has invalid url', $step->id());
                }
                try {
                    $this->assertHostAllowed($host);
                } catch (\Ws\Http\NanoGpt\AgentException $e) {
                    return sprintf('error: scenario step "%s" host not allowed: %s', $step->id(), $e->getMessage());
                }
            }
        }

        // 变量覆盖(design/27 §1:调用方可收窄 outputs,不扩充签名;vars 合并覆盖初值)
        if (isset($args['vars']) && \is_array($args['vars']) && $args['vars'] !== []) {
            $scenario->variables = $this->mergeVars($scenario->variables, $args['vars']);
        }
        $outputs = $scenario->outputs;
        if (isset($args['outputs']) && \is_array($args['outputs']) && $args['outputs'] !== []) {
            $unknown = array_diff(array_map('strval', $args['outputs']), $scenario->outputs);
            if ($unknown !== []) {
                return sprintf('error: outputs not declared by scenario: %s', implode(', ', $unknown));
            }
            $outputs = array_values(array_intersect($scenario->outputs, array_map('strval', $args['outputs'])));
        }

        // 场景引擎经注入式工厂:形态 B 复用本工具的 http(与形态 A 同源;
        // 白名单已在 execute 前置对场景全部 URL 校验)
        $factory = new \Ws\Http\Automated\StaticRequestFactory($this->http ?? new Request());
        $runner = new Runner($factory);
        $report = $runner->run($scenario);

        if (!$report->isSuccess()) {
            return sprintf("status: failed\n%s", (string) json_encode([
                'failedSteps' => $this->failedSteps($report),
                'variables'   => $report->variables(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $result = (new ScenarioOutput())->extract($report, $outputs);

        return sprintf(
            "status: ok\n%s",
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * vars 覆盖合并:仅覆盖已声明变量(未声明名忽略——防场景签名失真,V19 同源心智)。
     *
     * @param array<int, array{name: string, value: mixed, secret?: bool}> $variables
     * @param array<string, mixed> $vars
     * @return array<int, array{name: string, value: mixed, secret?: bool}>
     */
    private function mergeVars(array $variables, array $vars): array
    {
        foreach ($variables as $i => $entry) {
            $name = $entry['name'];
            if (array_key_exists($name, $vars)) {
                $variables[$i]['value'] = $vars[$name];
            }
        }

        return $variables;
    }

    /**
     * @return array<int, array{id: string, name: string, reason: string|null}>
     */
    private function failedSteps(Report $report): array
    {
        $failed = [];
        foreach ($report->steps() as $step) {
            if ($step->status === \Ws\Http\Automated\StepResult::STATUS_FAILED) {
                $failed[] = ['id' => $step->stepId, 'name' => $step->stepName, 'reason' => $step->failReason];
            }
        }

        return $failed;
    }
}
