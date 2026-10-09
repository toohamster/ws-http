<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\DTO;

class TokenUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $totalTokens,
    ) {}
}
