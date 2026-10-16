<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

use Ws\Http\NanoGpt\Transport\ChatTransportInterface;

/**
 * 内建 LLM 压缩器(design/24 §4.2,M2-b;design/28 D3 契约切换):压缩是一次
 * LLM 调用(诚实暴露,非本地函数)——传输依赖 = ChatTransportInterface。
 *
 * 防超限:待压缩段的 role=tool 消息(工具结果可能巨大)进入摘要 prompt 前
 * 截断到每条 2000 字符——阻断"超限→压缩→压缩请求自身超限"死锁;截断只影响
 * 摘要输入质量,不影响原 messages。
 */
final class LlmCompactor implements CompactorInterface
{
    private const TOOL_RESULT_CLAMP = 2000;

    /** @var ChatTransportInterface */
    private $transport;

    /** @var string 摘要模型 id */
    private $model;

    /** @var int 摘要生成上限(tokens) */
    private $summaryMaxTokens;

    public function __construct(ChatTransportInterface $transport, string $model, int $summaryMaxTokens = 1024)
    {
        $this->transport = $transport;
        $this->model = $model;
        $this->summaryMaxTokens = $summaryMaxTokens;
    }

    public function compact(array $messages): array
    {
        if ($messages === []) {
            throw new AgentException('Cannot compact an empty message segment', 612);
        }

        $transcript = $this->transcript($messages);

        try {
            $result = $this->transport->chat(
                [
                    ['role' => 'system', 'content' => 'You are a conversation summarizer. Produce a compact summary in Chinese preserving: task goals, key conclusions, unfinished items, important numeric values. Output plain text only.'],
                    ['role' => 'user', 'content' => $transcript],
                ],
                ['model' => $this->model, 'max_tokens' => $this->summaryMaxTokens]
            );
        } catch (AgentException $e) {
            throw new AgentException('Compaction LLM call failed: ' . $e->getMessage(), 612, $e);
        }

        $content = trim($result->content());
        if ($content === '') {
            throw new AgentException('Compaction LLM returned empty summary', 612);
        }

        return ['role' => 'user', 'content' => '【历史摘要】' . $content];
    }

    /**
     * 待压缩段 → 摘要 prompt 文本(tool 消息逐条截断到 TOOL_RESULT_CLAMP 字符)。
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function transcript(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'unknown');
            $content = (string) ($m['content'] ?? '');

            if ($role === 'tool' && strlen($content) > self::TOOL_RESULT_CLAMP) {
                $content = substr($content, 0, self::TOOL_RESULT_CLAMP);
            }

            $lines[] = sprintf('[%s] %s', $role, $content);
        }

        return implode("\n", $lines);
    }
}
