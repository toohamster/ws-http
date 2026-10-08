<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /context:会话用量(design/21 §8)——跨轮累积 usage + 当前模型 + 已注册工具。
 *
 * 类名 ShowContext(context 是 PHP/PSR 语境的保留观感词,且与 CcGpt\Context 撞名)。
 */
final class ShowContext implements CommandInterface
{
    public function name(): string
    {
        return 'context';
    }

    public function description(): string
    {
        return 'Show conversation usage, current model and registered tools';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        if ($context->agent === null) {
            $out->writeln('agent not configured (run /init first)', 'yellow');

            return true;
        }

        $usage = $context->agent->conversation()->usage();
        $out->writeln('model:    ' . ($context->model !== '' ? $context->model : '(none)'));
        // 双口径(design/24 §7):会话累计 usage(费用)+ 当前窗口占用(预算口径)
        $out->writeln(sprintf(
            'usage:    prompt=%d completion=%d total=%d',
            $usage['prompt_tokens'],
            $usage['completion_tokens'],
            $usage['total_tokens']
        ));
        $messages = $context->agent->conversation()->messages();
        if ($context->budget !== null) {
            $ratio = $context->budget->usageRatio($messages);
            $out->writeln(sprintf(
                'window:   ~%.0f%% of budget (est. %d tokens / %d messages)',
                $ratio * 100,
                $context->budget->estimateTokens($messages),
                count($messages)
            ));
        }
        $tools = $context->agent->tools()->names();
        $out->writeln('tools:    ' . ($tools === [] ? '(none)' : implode(', ', $tools)));
        $out->writeln('session:  ' . ($context->currentSessionId ?? '(none)'));

        return true;
    }
}
