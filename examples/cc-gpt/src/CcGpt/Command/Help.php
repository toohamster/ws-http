<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /help:命令列表。
 */
final class Help implements CommandInterface
{
    public function name(): string
    {
        return 'help';
    }

    public function description(): string
    {
        return 'Show this command list';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;
        $out->writeln('commands:', 'bright_cyan');
        foreach ($context->commands !== null ? $context->commands->all() : [] as $command) {
            $out->writeln(sprintf('  /%-10s %s', $command->name(), $command->description()));
        }
        $out->writeln('anything else is sent to the model; /quit or Ctrl-D exits', 'gray');

        return true;
    }
}
