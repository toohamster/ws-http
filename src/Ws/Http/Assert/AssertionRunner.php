<?php

declare(strict_types=1);

namespace Ws\Http\Assert;

use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Response;
use Ws\Http\Support\ResultSet;

/**
 * 收集式断言执行器(design/12 §5):L3 自动化引擎使用。
 *
 * 全量执行不短路;单条断言异常(路径无匹配等)捕获为 failed 结果,不中断。
 */
final class AssertionRunner
{
    /** @var ExpressionEvaluator */
    private $evaluator;

    public function __construct(?ExpressionEvaluator $evaluator = null)
    {
        $this->evaluator = $evaluator ?? new ExpressionEvaluator();
        Comparison::bootBuiltins();
    }

    /**
     * Schema v2 asserts 字段 → Assertion[](design/14 §4.5 / design/12 §3)。
     *
     * @param array<int, array<string, mixed>> $raw
     * @return Assertion[]
     */
    public static function fromArray(array $raw): array
    {
        $assertions = [];
        foreach ($raw as $item) {
            $assertions[] = new Assertion(
                (string) ($item['source'] ?? ''),
                (string) ($item['path'] ?? ''),
                (string) ($item['op'] ?? 'eq'),
                $item['expected'] ?? null,
                isset($item['name']) ? (string) $item['name'] : null
            );
        }

        return $assertions;
    }

    /**
     * 全量执行;返回 ResultSet(消费方:CLI/报告;S7.5 统一容器)。
     *
     * @param Assertion[] $assertions
     */
    public function run(Response $response, array $assertions): ResultSet
    {
        return new ResultSet($this->runAll($response, $assertions));
    }

    /**
     * 裸数组形态(内部/测试用)。
     *
     * @param Assertion[] $assertions
     * @return AssertionResult[]
     */
    public function runAll(Response $response, array $assertions): array
    {
        $results = [];
        foreach ($assertions as $assertion) {
            $results[] = $this->runOne($response, $assertion);
        }

        return $results;
    }

    private function runOne(Response $response, Assertion $assertion): AssertionResult
    {
        $actual = $this->extractActual($response, $assertion);

        try {
            $passed = Comparison::compare($assertion->op, $actual, $assertion->expected);
            $message = $passed ? null : $this->formatFailure($response, $assertion, $actual);
        } catch (\Throwable $e) {
            // 数值化失败/未知操作符等 → failed 结果,不中断
            $passed = false;
            $message = $this->formatFailure($response, $assertion, $actual, $e);
        }

        return new AssertionResult($assertion, $passed, $message, $actual);
    }

    /**
     * 单条执行(Watcher 流式复用同一实现,失败行为由调用方决定)。
     */
    public function runOnePublic(Response $response, Assertion $assertion): AssertionResult
    {
        return $this->runOne($response, $assertion);
    }

    /**
     * 按 source 提取 actual(design/12 §3 表)。
     *
     * @return mixed
     */
    private function extractActual(Response $response, Assertion $assertion)
    {
        switch ($assertion->source) {
            case 'status':
                return $response->code;
            case 'time':
                return $response->totalTime();
            case 'raw_body':
                return $response->rawBody;
            case 'header':
                return $response->header($assertion->path);
            case 'json':
                $result = $this->evaluator->evaluate($response->body, $assertion->path);
                return $result->first(); // 无匹配 → null,由比较语义判定(fail 消息含路径)
            case 'json_root':
                return $response->body; // 整个解析后 body(body=false 即解析失败,由 body_json 判定)
            default:
                throw new \InvalidArgumentException(sprintf('Unknown assertion source: %s', $assertion->source));
        }
    }

    /**
     * 失败消息模板(design/12 §4.2)。
     *
     * @param mixed $actual
     */
    private function formatFailure(Response $response, Assertion $assertion, $actual, ?\Throwable $cause = null): string
    {
        $label = $assertion->name ?? ($assertion->source . ($assertion->path !== '' ? ' ' . $assertion->path : ''));
        $lines = [
            sprintf('Assertion failed [%s] %s %s', $label, $assertion->op, $this->pretty($assertion->expected)),
            sprintf('  expected: %s', $this->pretty($assertion->expected)),
            sprintf('  actual:   %s', $this->pretty($actual)),
        ];

        if ($cause !== null) {
            $lines[] = sprintf('  cause:    %s', $cause->getMessage());
        }

        return implode("\n", $lines);
    }

    /**
     * @param mixed $value
     */
    private function pretty($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json === false ? var_export($value, true) : $json;
    }
}
