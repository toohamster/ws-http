<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\DTO;

class StreamChunk
{
    public function __construct(
        public readonly string $content,
        public readonly bool $done,
        public readonly ?string $reasoningContent = null,
        public readonly ?array $toolCalls = null,
    ) {}
}
