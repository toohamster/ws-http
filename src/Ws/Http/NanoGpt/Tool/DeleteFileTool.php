<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\ToolInterface;

/**
 * 删除文件/空目录(design/21 §8 评审决策):rm 不进 ExecTool 信任集——
 * 删除作为内建工具走 Sandbox,绝对边界(只能在 .work/.runtime 内删)。
 */
final class DeleteFileTool implements ToolInterface
{
    /** @var \Ws\Http\NanoGpt\Sandbox */
    private $sandbox;

    public function __construct(\Ws\Http\NanoGpt\Sandbox $sandbox)
    {
        $this->sandbox = $sandbox;
    }

    public function name(): string
    {
        return 'delete_file';
    }

    public function description(): string
    {
        return 'Delete a file or an empty directory inside the sandbox workspace (refuses non-empty directories).';
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

        // 防呆:root 本体不可删
        if ($resolved === $this->sandbox->root()) {
            return 'error: cannot delete the workspace root itself';
        }

        if (is_dir($resolved)) {
            // 空目录才允许(rmdir 语义);递归删除必须显式逐层,防误删子树
            if (!@rmdir($resolved)) {
                return sprintf('error: "%s" is a non-empty directory (delete its files first)', $path);
            }

            return sprintf('ok: removed directory %s', $path);
        }

        if (!\is_file($resolved) && !is_link($resolved)) {
            return sprintf('error: "%s" does not exist', $path);
        }

        if (!@unlink($resolved)) {
            return sprintf('error: cannot delete "%s"', $path);
        }

        return sprintf('ok: deleted %s', $path);
    }
}
