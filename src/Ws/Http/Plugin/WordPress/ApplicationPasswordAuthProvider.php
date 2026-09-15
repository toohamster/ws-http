<?php

declare(strict_types=1);

namespace Ws\Http\Plugin\WordPress;

use Ws\Http\Contract\AuthProviderInterface;
use Ws\Http\RequestOptions;

/**
 * WordPress Application Password 认证 = Basic(user, appPassword)(design/17 §4.2,core 原生支持)。
 */
final class ApplicationPasswordAuthProvider implements AuthProviderInterface
{
    /**
     * @param array<string, mixed> $config 期望含 user / appPassword
     */
    public function apply(RequestOptions $options, array $config): RequestOptions
    {
        $user = (string) ($config['user'] ?? '');
        $appPassword = (string) ($config['appPassword'] ?? '');

        if ($user === '') {
            throw new \InvalidArgumentException('WordPress auth requires "user"');
        }

        return $options->withAuth($user, $appPassword);
    }
}
