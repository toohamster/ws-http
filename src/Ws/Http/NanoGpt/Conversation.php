<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 会话状态(design/21 §6):messages[](OpenAI wire 格式纯数组)+ 跨轮 usage 累积。
 *
 * 纯内存状态;messages 序列化即请求体,无转换层;持久化(session.json)列扩展预留。
 */
final class Conversation
{
    /** @var array<int, array<string, mixed>> */
    private $messages = [];

    /** @var array{prompt_tokens: int, completion_tokens: int, total_tokens: int} */
    private $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    /** @var array<string, mixed>|null */
    private $system;

    /**
     * @param array<string, mixed>|null $system role=system 消息(run 前置于请求,不入 messages 快照)
     */
    public function __construct(?array $system = null)
    {
        $this->system = $system;
    }

    public function system(): ?array
    {
        return $this->system;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function append(array $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * 组装请求体 messages(system 前置 + 会话)。
     *
     * @return array<int, array<string, mixed>>
     */
    public function forRequest(): array
    {
        return $this->system !== null
            ? array_merge([$this->system], $this->messages)
            : $this->messages;
    }

    /**
     * 从响应 body->usage(stdClass)累积。
     */
    public function accumulateUsage(object $usage): void
    {
        $this->usage['prompt_tokens'] += (int) ($usage->prompt_tokens ?? 0);
        $this->usage['completion_tokens'] += (int) ($usage->completion_tokens ?? 0);
        $this->usage['total_tokens'] += (int) ($usage->total_tokens ?? 0);
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function usage(): array
    {
        return $this->usage;
    }

    public function reset(): void
    {
        $this->messages = [];
        $this->usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    }
}
