<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use SnowmanNunu\Ai\Exceptions\AiAuthException;
use SnowmanNunu\Ai\Exceptions\AiException;
use SnowmanNunu\Ai\Exceptions\AiRateLimitException;
use SnowmanNunu\Ai\Exceptions\AiServerException;
use SnowmanNunu\Ai\Exceptions\AiTimeoutException;

trait HandlesGuzzleExceptions
{
    protected function handleGuzzleException(GuzzleException $e): AiException
    {
        $response = null;
        $statusCode = null;

        if ($e instanceof ClientException || $e instanceof ServerException) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            $statusCode = $e->getResponse()->getStatusCode();
        } elseif ($e instanceof ConnectException || $e instanceof TooManyRedirectsException) {
            return new AiTimeoutException('Request timeout', null, null, $e);
        }

        $message = $response['error']['message'] ?? $e->getMessage();

        return match ($statusCode) {
            401 => new AiAuthException($message, $response, $statusCode, $e),
            429 => new AiRateLimitException($message, $response, $statusCode, $e),
            500, 502, 503, 504 => new AiServerException($message, $response, $statusCode, $e),
            default => new AiException($message, $response, $statusCode, $e),
        };
    }
}
