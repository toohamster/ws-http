<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Contract\ExtractionOutcome;
use Ws\Http\Contract\ExtractorInterface;
use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Response;
use Ws\Http\StrKit;

/**
 * 变量提取器(design/15 §3):六内建 source 的注册表 + 按序执行。
 *
 * - 不抛异常:一切失败收集为 failures(引擎收集语义);
 * - 同步骤内前序提取写入的变量对后序可见(按 rules 顺序执行);
 * - source 注册表开放:plugin/业务方可注册新 source(如 XML 提取)。
 */
final class VarExtractor
{
    /** @var array<string, ExtractorInterface> */
    private static $sources = [];

    /** @var ExpressionEvaluator|null json source 共享求值器 */
    private $evaluator;

    public function __construct(?ExpressionEvaluator $evaluator = null)
    {
        $this->evaluator = $evaluator;
    }

    /**
     * 注册新 source(覆盖同名内建项)。
     */
    public static function registerSource(string $name, ExtractorInterface $impl): void
    {
        self::$sources[$name] = $impl;
    }

    /**
     * 仅测试用:清空 source 注册表。
     */
    public static function resetForTest(): void
    {
        self::$sources = [];
    }

    /**
     * @param array<int, array<string, mixed>> $rules VarRule 裸形态(var/source/path/multiple/onMissing/defaultValue)
     */
    public function extract(Response $response, array $rules, VariableScope $scope): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($rules as $rule) {
            $this->extractOne($response, $rule, $scope, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function extractOne(Response $response, array $rule, VariableScope $scope, ExtractionResult $result): void
    {
        $var = (string) ($rule['var'] ?? '');
        $source = (string) ($rule['source'] ?? '');
        $path = (string) ($rule['path'] ?? '');
        $multiple = (bool) ($rule['multiple'] ?? false);
        $onMissing = (string) ($rule['onMissing'] ?? 'fail');

        try {
            $outcome = $this->extractViaSource($response, $source, $path, $multiple);
        } catch (\Throwable $e) {
            $outcome = ExtractionOutcome::missing();
            $result->failures[] = [
                'var'     => $var,
                'message' => sprintf('extract var "%s" failed: %s', $var, $e->getMessage()),
            ];
            return;
        }

        if (!$outcome->found) {
            $this->handleMissing($var, $path, $onMissing, $rule, $scope, $result);
            return;
        }

        $value = $outcome->value;

        // multiple 语义已由 source 层处理(json=first/all,header=拼接/数组);
        // template 例外:默认取首个命名字段,multiple=true 整个关联数组回写
        if ($source === 'template') {
            if ($multiple) {
                $scope->set($var, \is_array($value) ? $value : [$value]);
            } else {
                $first = \is_array($value) ? (array_values($value)[0] ?? null) : $value;
                $scope->set($var, $first);
            }
        } else {
            $scope->set($var, $value);
        }

        $result->written[] = ['var' => $var, 'value' => $scope->get($var)->value];
    }

    /**
     * @return ExtractionOutcome
     */
    private function extractViaSource(Response $response, string $source, string $path, bool $multiple)
    {
        // 注册表优先(Extension 覆盖内建)
        if (isset(self::$sources[$source])) {
            return self::$sources[$source]->extract($response, $path);
        }

        switch ($source) {
            case 'json':
                $evaluated = ($this->evaluator ?? new ExpressionEvaluator())->evaluate($response->body, $path);
                if ($evaluated->isEmpty()) {
                    return ExtractionOutcome::missing();
                }

                return ExtractionOutcome::found($multiple ? $evaluated->all() : $evaluated->first());

            case 'header':
                if ($multiple) {
                    $values = $response->headers->all($path);
                    return $values === [] ? ExtractionOutcome::missing() : ExtractionOutcome::found($values);
                }
                $value = $response->header($path);
                return $value === null ? ExtractionOutcome::missing() : ExtractionOutcome::found($value);

            case 'raw_body':
                return ExtractionOutcome::found($response->rawBody);

            case 'status':
                return ExtractionOutcome::found($response->code);

            case 'time':
                return ExtractionOutcome::found($response->totalTime());

            case 'template':
                $matched = StrKit::extract($response->rawBody, $path);
                return $matched === [] ? ExtractionOutcome::missing() : ExtractionOutcome::found($matched);

            default:
                throw new \InvalidArgumentException(sprintf('Unknown extract source: %s', $source));
        }
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function handleMissing(string $var, string $path, string $onMissing, array $rule, VariableScope $scope, ExtractionResult $result): void
    {
        switch ($onMissing) {
            case 'fail':
                $result->failures[] = [
                    'var'     => $var,
                    'message' => sprintf('extract var "%s" matched nothing: %s', $var, $path),
                ];
                break;

            case 'skip':
                $result->warnings[] = sprintf(
                    'extract var "%s" matched nothing (%s), kept current value',
                    $var,
                    $path
                );
                break;

            case 'default':
                $scope->set($var, $rule['defaultValue'] ?? '');
                $result->written[] = ['var' => $var, 'value' => $scope->get($var)->value];
                break;

            default:
                $result->failures[] = [
                    'var'     => $var,
                    'message' => sprintf('extract var "%s": unknown onMissing strategy "%s"', $var, $onMissing),
                ];
        }
    }
}
