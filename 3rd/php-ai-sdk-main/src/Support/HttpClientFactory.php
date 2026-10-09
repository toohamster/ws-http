<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Support;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpClientFactory
{
    public static function make(array $config): Client
    {
        $options = [
            'base_uri' => $config['base_url'] ?? null,
            'timeout' => $config['timeout'] ?? 60,
            'headers' => $config['headers'] ?? [],
        ];

        if (! empty($config['proxy'])) {
            $options['proxy'] = $config['proxy'];
        }

        $stack = self::createHandlerStack($config);

        $retry = (int) ($config['retry'] ?? 0);
        if ($retry > 0) {
            $stack->push(self::createRetryMiddleware($config), 'ai_retry');
        }

        $options['handler'] = $stack;

        return new Client($options);
    }

    protected static function createHandlerStack(array $config): HandlerStack
    {
        $handler = $config['handler'] ?? null;

        if ($handler instanceof HandlerStack) {
            return $handler;
        }

        return HandlerStack::create($handler);
    }

    protected static function createRetryMiddleware(array $config): callable
    {
        $maxRetries = (int) ($config['retry'] ?? 0);
        $delayMs = (int) ($config['retry_delay'] ?? 1000);
        $multiplier = (float) ($config['retry_multiplier'] ?? 2.0);
        $onStatus = (array) ($config['retry_on_status'] ?? [429, 500, 502, 503, 504]);

        $decider = function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?Exception $exception = null
        ) use ($maxRetries, $onStatus): bool {
            if ($retries >= $maxRetries) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                return true;
            }

            if ($response !== null && in_array($response->getStatusCode(), $onStatus, true)) {
                return true;
            }

            return false;
        };

        $delay = function (
            int $retries,
            ?ResponseInterface $response = null,
            ?RequestInterface $request = null
        ) use ($delayMs, $multiplier): int {
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $retryAfter = (int) $response->getHeader('Retry-After')[0];

                return max(0, $retryAfter * 1000);
            }

            return (int) ($delayMs * ($multiplier ** $retries));
        };

        return Middleware::retry($decider, $delay);
    }
}
