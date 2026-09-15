<?php

declare(strict_types=1);

namespace Ws\Http\Plugin\OpenAI;

use Ws\Http\Contract\AuthProviderInterface;
use Ws\Http\RequestOptions;

/**
 * OpenAI Bearer 认证(design/17 §4.1)。
 */
final class BearerAuthProvider implements AuthProviderInterface
{
    /**
     * @param array<string, mixed> $config 期望含 apiKey
     */
    public function apply(RequestOptions $options, array $config): RequestOptions
    {
        $apiKey = (string) ($config['apiKey'] ?? '');

        if ($apiKey === '') {
            throw new \InvalidArgumentException('OpenAI auth requires "apiKey"');
        }

        return $options->withDefaultHeader('Authorization', 'Bearer ' . $apiKey);
    }
}
