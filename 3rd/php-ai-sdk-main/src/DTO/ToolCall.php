<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\DTO;

class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $arguments,
    ) {}
}
