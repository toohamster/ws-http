<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /memory(design/24 §7,M4):长期记忆管理——
 *   /memory remember <text>  追加(setSystem 即时生效,下一轮 forRequest 含记忆块)
 *   /memory forget <n>       删第 n 条(1-based)
 *   /memory list             列出全部
 */
final class Memory implements CommandInterface
{
    public function name(): string
    {
        return 'memory';
    }

    public function description(): string
    {
        return 'Long-term memory: /memory remember <text> | forget <n> | list';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        if ($context->memory === null) {
            $out->writeln('memory not configured', 'yellow');

            return true;
        }

        $sub = (string) ($args[0] ?? 'list');

        if ($sub === 'remember') {
            $text = trim(implode(' ', array_slice($args, 1)));
            if ($text === '') {
                $out->writeln('usage: /memory remember <text>', 'yellow');

                return true;
            }
            $context->memory->remember($text);
            // 即时注入:下一轮 forRequest 生效(setSystem 动机,design/24 §6)
            if ($context->agent !== null) {
                $context->applyMemoryToSystem();
            }
            $out->writeln('remembered: ' . $text, 'green');

            return true;
        }

        if ($sub === 'forget') {
            $n = (int) ($args[1] ?? 0);
            if ($n < 1) {
                $out->writeln('usage: /memory forget <n> (1-based)', 'yellow');

                return true;
            }
            $context->memory->forget($n - 1);
            if ($context->agent !== null) {
                $context->applyMemoryToSystem();
            }
            $out->writeln('forgot #' . $n, 'green');

            return true;
        }

        if ($sub === 'list') {
            $all = $context->memory->recall();
            if ($all === []) {
                $out->writeln('(no memory entries)', 'gray');

                return true;
            }
            foreach ($all as $i => $e) {
                $tags = $e['tags'] !== [] ? ' [' . implode(',', $e['tags']) . ']' : '';
                $out->writeln(sprintf('  %d) %s%s', $i + 1, $e['text'], $tags));
            }

            return true;
        }

        $out->writeln('unknown subcommand: ' . $sub . ' (remember|forget|list)', 'yellow');

        return true;
    }
}
