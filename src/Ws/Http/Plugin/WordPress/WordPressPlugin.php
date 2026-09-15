<?php

declare(strict_types=1);

namespace Ws\Http\Plugin\WordPress;

use Ws\Http\Contract\PluginContext;
use Ws\Http\Contract\PluginInterface;

/**
 * WordPress plugin 装配(design/17 §4.2)。
 */
final class WordPressPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'wordpress';
    }

    public function register(PluginContext $ctx): void
    {
        $ctx->addAuth('wordpress.application-password', new ApplicationPasswordAuthProvider());
    }
}
