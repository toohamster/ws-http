<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;
use Ws\Http\NanoGpt\CompactorInterface;
use Ws\Http\NanoGpt\ConversationStoreInterface;
use Ws\Http\NanoGpt\FileConversationStore;

/**
 * /compact(design/24 §7,M4):手动压缩——保留最近 keep 轮(默认 2),更早消息交
 * Compactor;压缩后自动 save session。阻塞语义:同步 REPL 天然保证(压缩发生在
 * 两次 readLine 之间,期间无提示符)。
 */
final class Compact implements CommandInterface
{
    /** @var CompactorInterface */
    private $compactor;

    /** @var ConversationStoreInterface|null */
    private $store;

    public function __construct(CompactorInterface $compactor, ?ConversationStoreInterface $store = null)
    {
        $this->compactor = $compactor;
        $this->store = $store;
    }

    public function name(): string
    {
        return 'compact';
    }

    public function description(): string
    {
        return 'Compact history: /compact [keep] (keep turns, default 2)';
    }

    public function execute(array $args, Context $context): bool
    {
        $keep = (isset($args[0]) && preg_match('/^\d+$/', (string) $args[0]) === 1) ? max(1, (int) $args[0]) : 2;

        $done = $this->run($context, $keep, true);
        if (!$done) {
            // 未触发(消息不足/无 agent)——提示已由 run 输出
        }

        return true;
    }

    /**
     * 执行压缩(手动/自动共用核心):保留最近 $keep 轮(2*keep 条),更早段压缩为摘要。
     *
     * @return bool 是否实际压缩
     */
    public function run(Context $context, int $keep, bool $verbose): bool
    {
        if ($context->agent === null) {
            if ($verbose) {
                $context->output->writeln('agent not configured (run /init first)', 'yellow');
            }

            return false;
        }

        $conversation = $context->agent->conversation();
        $messages = $conversation->messages();
        $keepCount = $keep * 2; // 一轮 = user + assistant
        if (count($messages) <= $keepCount) {
            if ($verbose) {
                $context->output->writeln(sprintf('no need to compact (%d messages ≤ keep %d turns)', count($messages), $keep), 'gray');
            }

            return false;
        }

        $split = count($messages) - $keepCount;
        // 切分点对齐 user 边界(向下取第一条 role=user 且非工具对内部)
        while ($split > 0 && $messages[$split]['role'] !== 'user') {
            $split--;
        }
        if ($split === 0) {
            if ($verbose) {
                $context->output->writeln('no user boundary to compact at', 'yellow');
            }

            return false;
        }

        $older = array_slice($messages, 0, $split);
        if ($verbose) {
            $context->output->writeln(sprintf('compacting… (%d messages)', count($older)), 'gray');
        }

        try {
            $summary = $this->compactor->compact($older);
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            // 612:原 messages 不动("先算后换"),壳提示可重试或 /rewind
            $context->output->writeln('compact failed [' . $e->getCode() . ']: ' . $e->getMessage() . ' — retry or /rewind', 'red');

            return false;
        }

        // 先算后换:摘要已得 → 整体重建 = 摘要前置 + keep 段
        // (truncateFrom 只能"从 index 截到尾",无法表达"丢头部留尾部";
        //  reset 会清 usage → 先暂存 usage,重建后恢复——压缩不动跨轮累计口径)
        $kept = array_slice($messages, $split);
        $usageBefore = $conversation->usage();
        $conversation->reset();
        $conversation->append($summary);
        foreach ($kept as $m) {
            $conversation->append($m);
        }
        $conversation->restoreUsage($usageBefore);

        if ($verbose) {
            $context->output->writeln(sprintf('%d → %d messages', count($messages), count($conversation->messages())), 'green');
        }

        $this->save($context);

        return true;
    }

    /**
     * 自动压缩(每轮 run 前):needsCompact 且 compactAuto 开启 → 保留 2 轮。
     *
     * @return bool 是否实际压缩
     */
    public static function maybeAuto(Context $context, CompactorInterface $compactor): bool
    {
        if (!$context->compactAuto || $context->budget === null || $context->agent === null) {
            return false;
        }

        $messages = $context->agent->conversation()->messages();
        if (!$context->budget->needsCompact($messages)) {
            return false;
        }

        $context->output->writeln('[auto-compact] context nearing model window', 'yellow');

        return (new self($compactor, null))->run($context, 2, true);
    }

    private function save(Context $context): void
    {
        $store = $this->store ?? $context->sessionStore;
        if ($store === null || $context->currentSessionId === null || $context->agent === null) {
            return;
        }

        try {
            $store->save($context->currentSessionId, $context->agent->conversation()->snapshot());
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            $context->output->writeln('session save failed: ' . $e->getMessage(), 'yellow');
        }
    }
}
