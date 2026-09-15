<?php

declare(strict_types=1);

namespace Ws\Http\Plugin\OpenAI;

use Ws\Http\Contract\PluginContext;
use Ws\Http\Contract\PluginInterface;

/**
 * OpenAI plugin 装配(design/17 §4.1)。
 */
final class OpenAIPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'openai';
    }

    public function register(PluginContext $ctx): void
    {
        $ctx->addAuth('openai.bearer', new BearerAuthProvider());
    }
}
