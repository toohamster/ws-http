<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Support;

class Tool
{
    public static function define(string $name, string $description, array $parameters = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }
}
