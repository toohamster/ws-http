<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Support\ResultSet;

use Ws\Http\Response;

/**
 * 单步执行记录(design/16 §3.1):Runner 产出,Report/CLI 消费。
 *
 * durationMs 构造时通常未知(耗时在执行后才确定),由 Runner 在同包内补记 ——
 * 这是有意的包内可变点(@internal 语义),对外只读。
 */
final class StepResult
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** @var string */
    public $stepId;

    /** @var string */
    public $stepName;

    /** @var string http|delay */
    public $stepType;

    /** @var string passed|failed|skipped */
    public $status;

    /** @var string|null 失败根因摘要 */
    public $failReason;

    /** @var array{method: string, url: string}|null 请求摘要(脱敏前,输出时经 Redactor) */
    public $request;

    /** @var int|null */
    public $statusCode;

    /** @var float|null 步骤耗时(ms);由 Runner 在执行完成后补记(包内唯一可变点) */
    public $durationMs;

    /** @var string|null rawBody 前 2KB 摘录 */
    public $responseExcerpt;

    /** @var ResultSet AssertionResult 集合 */
    public $assertionResults;

    /** @var string[] warnings(extract skip 等) */
    public $warnings;

    /** @var array<string, mixed> 本步骤新提取的变量(名→值,脱敏后) */
    public $extractedVariables;

    /** @var array<string, mixed>|null 步骤类型专属附加信息(pause:source/var;design/22 §4) */
    public $meta;

    /**
     * @param array{method: string, url: string}|null $request
     * @param array<string, mixed> $extractedVariables
     * @param string[] $warnings
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        string $stepId,
        string $stepName,
        string $stepType,
        string $status,
        ?string $failReason = null,
        ?array $request = null,
        ?int $statusCode = null,
        ?float $durationMs = null,
        ?string $responseExcerpt = null,
        ?ResultSet $assertionResults = null,
        array $warnings = [],
        array $extractedVariables = [],
        ?array $meta = null
    ) {
        $this->stepId = $stepId;
        $this->stepName = $stepName;
        $this->stepType = $stepType;
        $this->status = $status;
        $this->failReason = $failReason;
        $this->request = $request;
        $this->statusCode = $statusCode;
        $this->durationMs = $durationMs;
        $this->responseExcerpt = $responseExcerpt;
        $this->assertionResults = $assertionResults ?? new ResultSet();
        $this->warnings = $warnings;
        $this->extractedVariables = $extractedVariables;
        $this->meta = $meta;
    }

    /**
     * skipped 工厂(failFast 终止后的未执行步骤)。
     */
    public static function skipped(string $stepId, string $stepName, string $reason): self
    {
        return new self($stepId, $stepName, 'http', self::STATUS_SKIPPED, $reason);
    }

    public function toArray(): array
    {
        return [
            'id'                 => $this->stepId,
            'name'               => $this->stepName,
            'type'               => $this->stepType,
            'status'             => $this->status,
            'failReason'         => $this->failReason,
            'request'            => $this->request,
            'statusCode'         => $this->statusCode,
            'durationMs'         => $this->durationMs,
            'responseExcerpt'    => $this->responseExcerpt,
            'assertions'         => $this->assertionResults->toArray(),
            'warnings'           => $this->warnings,
            'extractedVariables' => $this->extractedVariables,
            'meta'               => $this->meta,
        ];
    }
}
