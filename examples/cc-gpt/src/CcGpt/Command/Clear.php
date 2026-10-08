<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;
use Ws\Http\NanoGpt\ConversationStoreInterface;

/**
 * /clear(design/24 §7,M4):删当前 session + reset + 生成新 sessionId。
 */
final class Clear implements CommandInterface
{
    /** @var ConversationStoreInterface|null */
    private $store;

    public function __construct(?ConversationStoreInterface $store = null)
    {
        $this->store = $store;
    }

    public function name(): string
    {
        return 'clear';
    }

    public function description(): string
    {
        return 'Clear conversation and start a new session';
    }

    public function execute(array $args, Context $context): bool
    {
        $store = $this->store ?? $context->sessionStore;

        if ($store !== null && $context->currentSessionId !== null) {
            $store->delete($context->currentSessionId);
        }

        if ($context->agent !== null) {
            $context->agent->conversation()->reset();
        }

        $context->currentSessionId = date('Ymd-His'); // 新 session 身份
        $context->output->writeln('conversation cleared; session → ' . $context->currentSessionId, 'green');

        return true;
    }
}
