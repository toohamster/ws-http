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

    // ===== 以下为 design/24 §2(M1-a)增强:截断/快照/检查点 =====

    /**
     * system 运行期可变点(Memory 注入 /model 切换后即时生效,无需重建 Agent);
     * 对外仅壳/组装层调用(@internal 语义)。
     *
     * @param array<string, mixed>|null $system
     */
    public function setSystem(?array $system): void
    {
        $this->system = $system;
    }

    /**
     * rewind 底座:截断 index 及其后(新 messages 序列 = [0..index))。
     *
     * index 必须落在 user 边界(role=user 且非工具对内部)——工具调用对
     * (assistant.tool_calls + role=tool)不可拆,否则下一次请求违反 OpenAI 协议。
     * 只动 messages,不动 usage(跨轮累计口径)。
     *
     * @throws AgentException 605 截断点非法(非 user 边界/越界)
     */
    public function truncateFrom(int $messageIndex): void
    {
        if ($messageIndex < 0 || $messageIndex >= count($this->messages)
            || $this->messages[$messageIndex]['role'] !== 'user') {
            throw new AgentException(
                sprintf('Truncation index %d is not a user boundary', $messageIndex),
                605
            );
        }

        $this->messages = array_slice($this->messages, 0, $messageIndex);
    }

    /**
     * 检查点列表:role=user 消息的 (index, isSummary, 前缀文本)——/rewind 展示与截断点选择。
     * 压缩摘要消息(content 以【历史摘要】开头)标记 isSummary(可选为截断点)。
     *
     * @return array<int, array{index: int, isSummary: bool, prefix: string}>
     */
    public function userCheckpoints(int $prefixWidth = 40): array
    {
        $cps = [];
        foreach ($this->messages as $i => $m) {
            if (($m['role'] ?? '') !== 'user') {
                continue;
            }
            $text = (string) ($m['content'] ?? '');
            $isSummary = strpos($text, '【历史摘要】') === 0;
            $prefix = mb_strlen($text) > $prefixWidth
                ? mb_substr($text, 0, $prefixWidth) . '…'
                : $text;
            $cps[] = ['index' => $i, 'isSummary' => $isSummary, 'prefix' => $prefix];
        }

        return $cps;
    }

    /**
     * 持久化底座:完整状态(v 版本字段 + messages + usage + system)。
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'v'        => 1,
            'messages' => $this->messages,
            'usage'    => $this->usage,
            'system'   => $this->system,
        ];
    }

    /**
     * 从 snapshot 恢复完整状态。
     *
     * @param array<string, mixed> $snapshot
     * @throws AgentException 611 未知版本(session 格式不兼容,引导 /clear 重建)
     */
    public static function restore(array $snapshot): self
    {
        if ((int) ($snapshot['v'] ?? 0) !== 1) {
            throw new AgentException(
                sprintf('Unknown session snapshot version (v=%s)', var_export($snapshot['v'] ?? null, true)),
                611
            );
        }

        $c = new self();
        $c->messages = \is_array($snapshot['messages'] ?? null) ? $snapshot['messages'] : [];
        $usage = \is_array($snapshot['usage'] ?? null) ? $snapshot['usage'] : [];
        $c->usage = [
            'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens'      => (int) ($usage['total_tokens'] ?? 0),
        ];
        $c->system = \is_array($snapshot['system'] ?? null) ? $snapshot['system'] : null;

        return $c;
    }
}
