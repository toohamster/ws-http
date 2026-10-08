<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 上下文预算判定(design/24 §4.1,M2-a):保守上界估算 + 80% 阈值 + 占用比。
 *
 * 估算公式:ceil(UTF-8 字节数 / 3)——中文 ≈1 token/字偏保守,ASCII ≈4 字节/token 偏保守;
 * 零依赖,不精确但方向正确(只会高估不会低估)。
 */
final class ContextBudget
{
    /** @var int 模型上下文窗口(tokens) */
    private $contextWindow;

    /** @var int 预留输出空间(tokens) */
    private $reserveForOutput;

    public function __construct(int $contextWindow, int $reserveForOutput = 4096)
    {
        $this->contextWindow = $contextWindow;
        $this->reserveForOutput = $reserveForOutput;
    }

    /**
     * 保守上界估算:对整个 messages 结构做 JSON 序列化后按字节计。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    public function estimateTokens(array $messages): int
    {
        if ($messages === []) {
            return 0;
        }

        $bytes = strlen(json_encode($messages, JSON_UNESCAPED_UNICODE));

        return (int) ceil($bytes / 3);
    }

    /**
     * 估算 ≥ 预算 80% → true(预算 = contextWindow - reserveForOutput)。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    public function needsCompact(array $messages): bool
    {
        $budget = $this->contextWindow - $this->reserveForOutput;
        if ($budget <= 0) {
            return true;
        }

        return $this->estimateTokens($messages) >= (int) ($budget * 0.8);
    }

    /**
     * 占用比(0–1,/context 展示):估算 / contextWindow。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    public function usageRatio(array $messages): float
    {
        if ($this->contextWindow <= 0) {
            return 1.0;
        }

        return min(1.0, $this->estimateTokens($messages) / $this->contextWindow);
    }
}
