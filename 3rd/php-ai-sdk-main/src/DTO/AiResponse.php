<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\DTO;

class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly TokenUsage $usage,
        public readonly string $driver,
        public readonly array $raw,
        public readonly ?array $toolCalls = null,
        public readonly ?string $reasoningContent = null,
        public readonly ?string $finishReason = null,
        public readonly ?string $id = null,
        public readonly ?int $created = null,
    ) {}
}
