<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\CommandExecKernel;

/**
 * 文件名查找(design/25 §3.1):find -name。
 */
final class FindTool extends CommandToolBase
{
    public function __construct(CommandExecKernel $kernel)
    {
        parent::__construct($kernel);
    }

    public function name(): string
    {
        return 'find_files';
    }

    public function description(): string
    {
        return 'Find files by glob-style name pattern under a directory (find <path> -name <pattern>).';
    }

    protected function template(): string
    {
        return 'find {path} -name {pattern}';
    }

    protected function arguments(): array
    {
        return [
            'path'    => ['type' => 'string', 'description' => 'Directory to search', 'default' => '.'],
            'pattern' => ['type' => 'string', 'description' => 'File name pattern, e.g. *.json', 'required' => true],
        ];
    }
}
