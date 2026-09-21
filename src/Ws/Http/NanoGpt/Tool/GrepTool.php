<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\CommandExecKernel;

/**
 * 文本搜索(design/25 §3.1):grep -rn,claude code 同款能力形态。
 */
final class GrepTool extends CommandToolBase
{
    public function __construct(CommandExecKernel $kernel)
    {
        parent::__construct($kernel);
    }

    public function name(): string
    {
        return 'search_text';
    }

    public function description(): string
    {
        return 'Search a text pattern in workspace files recursively (grep -rn). Returns matching lines with file:line prefixes.';
    }

    protected function template(): string
    {
        return 'grep -rn {pattern} {path}';
    }

    protected function arguments(): array
    {
        return [
            'pattern' => ['type' => 'string', 'description' => 'Text pattern to search', 'required' => true],
            'path'    => ['type' => 'string', 'description' => 'Directory or file to search', 'default' => '.'],
        ];
    }
}
