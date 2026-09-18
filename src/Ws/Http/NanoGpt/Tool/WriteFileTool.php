<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\ToolInterface;

/**
 * 写文件(design/21 §4.1):父目录不存在自动创建;路径经 Sandbox(不存在路径的归一化校验在 Sandbox)。
 */
final class WriteFileTool implements ToolInterface
{
    /** @var \Ws\Http\NanoGpt\Sandbox */
    private $sandbox;

    public function __construct(\Ws\Http\NanoGpt\Sandbox $sandbox)
    {
        $this->sandbox = $sandbox;
    }

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Write content to a file inside the sandbox workspace (creates parent directories).';
    }

    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path'    => ['type' => 'string', 'description' => 'Relative path within the workspace'],
                'content' => ['type' => 'string', 'description' => 'File content to write'],
            ],
            'required'   => ['path', 'content'],
        ];
    }

    public function execute(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        $content = (string) ($args['content'] ?? '');

        try {
            $resolved = $this->sandbox->resolve($path);
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            return 'error: ' . $e->getMessage();
        }

        $dir = dirname($resolved);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return sprintf('error: cannot create directory "%s"', $dir);
        }

        $written = @\file_put_contents($resolved, $content);
        if ($written === false) {
            return sprintf('error: cannot write file "%s"', $path);
        }

        return sprintf('ok: wrote %d bytes to %s', $written, $path);
    }
}
