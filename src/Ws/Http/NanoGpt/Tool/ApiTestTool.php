<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\Assert\Comparison;
use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Request;

/**
 * 接口验证(design/25 §4.5,CI 心智):GET + 断言,输出断言报告。
 *
 * 与 ApiFetchTool(消费心智:取数据)正交——本工具回答"接口对不对"。
 * 断言复用 design/12 Comparison 注册表(eq/gt/contains/not_null/...);JSONPath 复用 design/13。
 */
final class ApiTestTool extends HttpToolBase
{
    public function __construct(array $allowHosts, ?Request $http = null, int $maxBody = 2048, bool $allowPrivateHosts = false)
    {
        parent::__construct($allowHosts, $http, $maxBody, $allowPrivateHosts);
    }

    public function name(): string
    {
        return 'api_test';
    }

    public function description(): string
    {
        return 'Verify an API endpoint: GET it and assert status code / JSON path. Returns assert: PASSED or FAILED with actual values.';
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
                'url'              => ['type' => 'string', 'description' => 'Absolute http(s) URL'],
                'expect_status'    => ['type' => 'number', 'description' => 'Expected HTTP status code (e.g. 200)'],
                'expect_json_path' => ['type' => 'string', 'description' => 'JSONPath into the response body (e.g. $.data.total)'],
                'expect_op'        => ['type' => 'string', 'description' => 'Comparison operator for the path value', 'enum' => ['eq', 'ne', 'gt', 'ge', 'lt', 'le', 'contains', 'not_contains', 'not_null', 'is_null']],
                'expect_value'     => ['type' => 'string', 'description' => 'Expected value for the operator (string form; compared after type-juggling)'],
            ],
            'required'   => ['url'],
        ];
    }

    /**
     * 断言语义(design/25 §4.5 评审钉死):
     * - expect_status 数字比较;
     * - expect_json_path + expect_op(默认 not_null)/expect_value:JSONPath 从 body 提取后按操作符比较;
     * - 断言全跑(不短路);输出 exit + 每条 assert: PASSED/FAILED(actual),任一 FAILED 整体 FAILED。
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        if ($this->allowHosts === []) {
            return 'error: api_test is disabled (no allowed hosts configured)';
        }

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

        $http = $this->http ?? new Request();
        try {
            $response = $http->send($this->method, $url, null, []);
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }

        $lines = ['exit: ' . $response->code];
        $allPassed = true;

        // ① 状态断言
        if (isset($args['expect_status']) && $args['expect_status'] !== '') {
            $expected = (int) $args['expect_status'];
            $passed = $response->code === $expected;
            // 全量断言不短路:FAILED 也继续跑剩余断言(design/25 §4.5)
            if (!$passed) {
                $allPassed = false;
            }
            $lines[] = sprintf(
                'assert: %s (status %s %d, actual %d)',
                $passed ? 'PASSED' : 'FAILED',
                $passed ? '==' : '!=',
                $expected,
                $response->code
            );
        }

        // ② JSONPath 断言
        $path = (string) ($args['expect_json_path'] ?? '');
        if ($path !== '') {
            $op = (string) ($args['expect_op'] ?? 'not_null');
            $expectedValue = $args['expect_value'] ?? null;

            $actual = null;
            $extractOk = false;
            if ($response->body !== false) {
                $evaluated = (new ExpressionEvaluator())->evaluate($response->body, $path);
                if (!$evaluated->isEmpty()) {
                    $actual = $evaluated->first();
                    $extractOk = true;
                }
            }

            $passed = $extractOk && Comparison::compare($op, $actual, $expectedValue);
            if (!$passed) {
                $allPassed = false;
            }

            $lines[] = sprintf(
                'assert: %s (%s %s %s, actual %s)',
                $passed ? 'PASSED' : 'FAILED',
                $path,
                $op,
                $expectedValue !== null ? (string) $expectedValue : '(none)',
                $extractOk ? (string) (is_scalar($actual) ? $actual : json_encode($actual, JSON_UNESCAPED_UNICODE)) : '(no match)'
            );
        }

        // body 摘录附尾(模型可诊断)
        $lines[] = 'body: ' . mb_substr($response->rawBody, 0, min($this->maxBody, 1024));

        return implode("\n", $lines) . ($allPassed ? '' : "\noverall: FAILED");
    }
}
