<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /quit:退出 REPL。
 */
final class Quit implements CommandInterface
{
    public function name(): string
    {
        return 'quit';
    }

    public function description(): string
    {
        return 'Exit cc-gpt (alias: /exit)';
    }

    public function execute(array $args, Context $context): bool
    {
        $context->output->writeln('bye', 'gray');

        return false;
    }
}
