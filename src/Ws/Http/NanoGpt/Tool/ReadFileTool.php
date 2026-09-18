<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\ToolInterface;

/**
 * 读文件(design/21 §4.1):内容 UTF-8 截断保护;路径经 Sandbox。
 */
final class ReadFileTool implements ToolInterface
{
    /** @var \Ws\Http\NanoGpt\Sandbox */
    private $sandbox;

    /** @var int 返回给模型的最大字节数 */
    private $maxBytes;

    public function __construct(\Ws\Http\NanoGpt\Sandbox $sandbox, int $maxBytes = 65536)
    {
        $this->sandbox = $sandbox;
        $this->maxBytes = $maxBytes;
    }

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read the content of a file inside the sandbox workspace. Returns the file content as text.';
    }

    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative path within the workspace'],
            ],
            'required'   => ['path'],
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

        $content = @\file_get_contents($resolved);
        if ($content === false) {
            return sprintf('error: cannot read file "%s"', $path);
        }

        if (strlen($content) > $this->maxBytes) {
            $content = substr($content, 0, $this->maxBytes) . "\n...[truncated]";
        }

        return $content;
    }
}
