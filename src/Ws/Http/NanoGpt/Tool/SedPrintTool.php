<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\CommandExecKernel;

/**
 * 按行段打印(design/25 §3.1):sed -n {range}p(打印,非 -i 原地改)。
 * range 槽 enum 无法列举 → 构造后置 pattern 校验由槽位元字符拒绝兜底(逗号/数字合法)。
 */
final class SedPrintTool extends CommandToolBase
{
    public function __construct(CommandExecKernel $kernel)
    {
        parent::__construct($kernel);
    }

    public function name(): string
    {
        return 'read_lines';
    }

    public function description(): string
    {
        return 'Print a line range of a file (sed -n). Range format: "start,end" (e.g. 5,20) or a single line number.';
    }

    protected function template(): string
    {
        return 'sed -n {range}p {path}';
    }

    protected function arguments(): array
    {
        return [
            'range' => ['type' => 'string', 'description' => 'Line range: "start,end" or single number', 'required' => true],
            'path'  => ['type' => 'string', 'description' => 'File path within workspace', 'required' => true],
        ];
    }

    /**
     * 追加 range 形态校验(design/25 §3.1:^\d+(,\d+)?$)。
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        $range = (string) ($args['range'] ?? '');
        if (preg_match('/^\d+(,\d+)?$/', $range) !== 1) {
            return 'error: range must match "start,end" or a single number (e.g. 5,20)';
        }

        return parent::execute($args);
    }
}
