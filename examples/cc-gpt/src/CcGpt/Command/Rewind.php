<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;
use Ws\Http\NanoGpt\ConversationStoreInterface;

/**
 * /rewind(design/24 §7,M4):列 userCheckpoints(摘要消息标记 [摘要],可选为截断点)
 * → 选序号 → truncateFrom;usage 不重置;文案明示"压缩前历史不可恢复"。
 */
final class Rewind implements CommandInterface
{
    public function name(): string
    {
        return 'rewind';
    }

    public function description(): string
    {
        return 'Rewind conversation to an earlier user turn';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        if ($context->agent === null) {
            $out->writeln('agent not configured (run /init first)', 'yellow');

            return true;
        }

        $conversation = $context->agent->conversation();
        $checkpoints = $conversation->userCheckpoints();
        if ($checkpoints === []) {
            $out->writeln('no checkpoints (empty conversation)', 'yellow');

            return true;
        }

        // 列表
        foreach ($checkpoints as $i => $cp) {
            $label = $cp['isSummary'] ? '[摘要] ' . $cp['prefix'] : $cp['prefix'];
            $out->writeln(sprintf('  %d) %s', $i + 1, $label));
        }
        $out->writeln('note: history before compaction is not recoverable; usage is kept', 'gray');

        $line = $context->input->readLine('rewind to [1-' . count($checkpoints) . ']: ');
        if ($line === null || trim($line) === '' || preg_match('/^\d+$/', trim($line)) !== 1) {
            $out->writeln('aborted: invalid selection', 'yellow');

            return true;
        }

        $index = (int) trim($line) - 1;
        if (!isset($checkpoints[$index])) {
            $out->writeln('aborted: no such checkpoint', 'yellow');

            return true;
        }

        try {
            $conversation->truncateFrom($checkpoints[$index]['index']);
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            $out->writeln('rewind failed [' . $e->getCode() . ']: ' . $e->getMessage(), 'red');

            return true;
        }

        $out->writeln(sprintf('rewound to checkpoint %d (%d messages remain)', $index + 1, count($conversation->messages())), 'green');

        return true;
    }
}
