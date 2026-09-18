<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\ToolInterface;

/**
 * 列目录(design/21 §4.1):名称+类型,不递归;路径经 Sandbox。
 */
final class ListDirTool implements ToolInterface
{
    /** @var \Ws\Http\NanoGpt\Sandbox */
    private $sandbox;

    public function __construct(\Ws\Http\NanoGpt\Sandbox $sandbox)
    {
        $this->sandbox = $sandbox;
    }

    public function name(): string
    {
        return 'list_dir';
    }

    public function description(): string
    {
        return 'List entries (names and types) of a directory inside the sandbox workspace. Non-recursive.';
    }

    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative directory path; empty for workspace root'],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        try {
            $resolved = $this->sandbox->resolve($path);
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            return 'error: ' . $e->getMessage();
        }

        if (!is_dir($resolved)) {
            return sprintf('error: "%s" is not a directory', $path);
        }

        $entries = @scandir($resolved);
        if ($entries === false) {
            return sprintf('error: cannot list "%s"', $path);
        }

        $lines = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $type = is_dir($resolved . '/' . $entry) ? 'dir' : 'file';
            $lines[] = sprintf('%s %s', $type, $entry);
        }

        return $lines === [] ? '(empty directory)' : implode("\n", $lines);
    }
}
