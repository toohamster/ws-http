<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * chat 响应值对象(design/28 §2):chat 响应解析收口——传输契约的统一返回类型。
 *
 * fromBody 容错:body 为 null / choices 缺失 / 字段缺失 → 空值兜底,不抛
 * (结构级异常由消费方自行判定,如 Agent 604)。
 */
final class ChatResult
{
    /** @var string assistant 文本(content 缺失 = 空串) */
    private $content;

    /** @var string|null 推理模型(deepseek-r1 系)thinking 字段;无 = null */
    private $reasoningContent;

    /** @var string|null finish_reason(stop/length/tool_calls…;缺失 = null) */
    private $finishReason;

    /** @var array<int, array{id: string, name: string, arguments: string, wire: object}> tool_calls 数组化(wire 保留原条目供回填) */
    private $toolCalls;

    /** @var array{prompt_tokens: int, completion_tokens: int, total_tokens: int} */
    private $usage;

    /**
     * @param array<int, array{id: string, name: string, arguments: string, wire: object}> $toolCalls
     * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $usage
     */
    public function __construct(
        string $content = '',
        ?string $reasoningContent = null,
        ?string $finishReason = null,
        array $toolCalls = [],
        array $usage = []
    ) {
        $this->content = $content;
        $this->reasoningContent = $reasoningContent;
        $this->finishReason = $finishReason;
        $this->toolCalls = $toolCalls;
        $this->usage = [
            'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens'      => (int) ($usage['total_tokens'] ?? 0),
        ];
    }

    /**
     * 从 Response::body(stdClass)解析;任何结构缺失容错为空值。
     */
    public static function fromBody(?object $body): self
    {
        if ($body === null) {
            return new self();
        }

        $choice = $body->choices[0] ?? null;
        $message = \is_object($choice) ? ($choice->message ?? null) : null;

        $toolCalls = [];
        $rawCalls = (\is_object($message) && isset($message->tool_calls) && \is_array($message->tool_calls))
            ? $message->tool_calls
            : [];
        foreach ($rawCalls as $call) {
            if (!\is_object($call)) {
                continue;
            }
            $toolCalls[] = [
                'id'        => (string) ($call->id ?? ''),
                'name'      => (string) ($call->function->name ?? ''),
                'arguments' => (string) ($call->function->arguments ?? ''),
                'wire'      => $call, // 回填 assistant.tool_calls 需要原 wire 条目
            ];
        }

        $usage = \is_object($body->usage ?? null)
            ? [
                'prompt_tokens'     => (int) ($body->usage->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($body->usage->completion_tokens ?? 0),
                'total_tokens'      => (int) ($body->usage->total_tokens ?? 0),
            ]
            : [];

        return new self(
            \is_object($message) ? (string) ($message->content ?? '') : '',
            (\is_object($message) && isset($message->reasoning_content)) ? (string) $message->reasoning_content : null,
            \is_object($choice) ? (($choice->finish_reason ?? null) !== null ? (string) $choice->finish_reason : null) : null,
            $toolCalls,
            $usage
        );
    }

    public function content(): string
    {
        return $this->content;
    }

    public function reasoningContent(): ?string
    {
        return $this->reasoningContent;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * @return array<int, array{id: string, name: string, arguments: string, wire: object}>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function usage(): array
    {
        return $this->usage;
    }

    public function hasUsage(): bool
    {
        return $this->usage['total_tokens'] > 0
            || $this->usage['prompt_tokens'] > 0
            || $this->usage['completion_tokens'] > 0;
    }
}
