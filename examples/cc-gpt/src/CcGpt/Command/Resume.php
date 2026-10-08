<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;
use Ws\Http\NanoGpt\Conversation;
use Ws\Http\NanoGpt\ConversationStoreInterface;

/**
 * /resume(design/24 §7,M4):无参列出 sessions;带 id 恢复其 snapshot
 * (当前未保存先提示)。session 身份归壳(currentSessionId)。
 */
final class Resume implements CommandInterface
{
    /** @var ConversationStoreInterface */
    private $store;

    public function __construct(ConversationStoreInterface $store)
    {
        $this->store = $store;
    }

    public function name(): string
    {
        return 'resume';
    }

    public function description(): string
    {
        return 'List sessions: /resume; restore one: /resume <id>';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        // 无参:列表形态
        if ($args === []) {
            $ids = $this->store->list();
            if ($ids === []) {
                $out->writeln('no saved sessions', 'yellow');

                return true;
            }
            foreach ($ids as $i => $id) {
                $mark = $id === $context->currentSessionId ? '→' : (string) ($i + 1);
                $out->writeln(sprintf('  %s %s', $mark, $id));
            }
            $out->writeln('restore: /resume <id>', 'gray');

            return true;
        }

        if ($context->agent === null) {
            $out->writeln('agent not configured (run /init first)', 'yellow');

            return true;
        }

        $target = (string) $args[0];
        $snapshot = $this->store->load($target);
        if ($snapshot === null) {
            $out->writeln('session not found: ' . $target, 'red');

            return true;
        }

        // 当前未保存先提示(壳在退出时自动 save;此处检测 lastSaved 标记)
        if ($context->currentSessionId !== null && $context->currentSessionId !== $target
            && $context->unsavedSince !== null && $this->store->load($context->currentSessionId) === null) {
            $out->writeln('current session not saved yet — /compact or quit saves it; restoring anyway', 'yellow');
        }

        try {
            $restored = Conversation::restore($snapshot);
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            $out->writeln('restore failed [' . $e->getCode() . ']: ' . $e->getMessage(), 'red');

            return true;
        }

        // 换入恢复的会话:重建 messages(reset 清 usage → 先暂存恢复的 usage 再写回)
        $conversation = $context->agent->conversation();
        $restoredUsage = $restored->usage();
        $conversation->reset();
        $conversation->setSystem($restored->system());
        foreach ($restored->messages() as $m) {
            $conversation->append($m);
        }
        $conversation->restoreUsage($restoredUsage);

        $context->currentSessionId = $target;
        $usage = $restored->usage();
        $out->writeln(sprintf(
            'resumed %s (%d messages, total tokens %d)',
            $target,
            count($restored->messages()),
            $usage['total_tokens']
        ), 'green');

        return true;
    }
}
