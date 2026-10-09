<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Exceptions;

use Exception;

class AiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?array $response = null,
        public readonly ?int $statusCode = null,
        ?Exception $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
