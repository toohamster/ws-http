<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\CommandExecKernel;

/**
 * 系统命令执行(design/25 §1 谱系特例):CommandToolBase 的"自由度拉满"端点——
 * 骨架为空(仅首 token 二进制名),可变部分 = 自由文本 cmd 槽。
 *
 * 正因为槽位自由,需要**专属补偿防线**(其它 CommandTool 子类不需要):
 * 1. 二进制白名单(运行期查表;rm/mv/sh/sudo 等不在名单即拒);
 * 2. 兜底黑名单(基类构造期 + 本工具运行期双层);
 * 3. shell 操作符扫描(; | && || ` $( ) > < 防拼接出白名单外的命令);
 * 4. 任意代码入口拒绝(php -r / -B,sh -c,node -e 等——要跑代码只能先 write_file 到草稿区再执行脚本文件);
 * 5. cwd 锁定 + 超时 + 输出截断(由 CommandExecKernel 承担,design/25 §2)。
 *
 * 诚实边界:防线 1–4 是"尽力保证"(进程级文件系统隔离纯 PHP 做不到绝对);
 * 文件读写删走内建文件工具(Sandbox 绝对边界),exec 仅用于运行草稿区脚本。
 * FullPreset 默认不含本工具——经 withExec() 显式授权(名单可加减)。
 */
final class ExecTool extends CommandToolBase
{
    /** @var string[] 允许的可执行名;空表 = 禁用 */
    private $allowBinaries;

    /** @var string[] 禁止的 shell 操作符 */
    private $denyOperators;

    /** @var array<string, string> 任意代码入口:[flag => 适用二进制(前缀匹配)] */
    private $denyFlags;

    /** @var string 命令工作目录(内核参数回读用) */
    private $cwd;

    /** @var int 超时秒 */
    private $timeout;

    /** @var int 输出截断长度 */
    private $maxOutput;

    /**
     * @param string[] $allowBinaries
     * @param string[]|null $denyBinaries null = 默认黑名单
     * @param array<string, string>|null $denyFlags null = 默认任意代码入口表
     */
    public function __construct(
        array $allowBinaries,
        string $cwd,
        int $timeout = 30,
        int $maxOutput = 2048,
        ?array $denyBinaries = null,
        ?array $denyFlags = null
    ) {
        parent::__construct(new CommandExecKernel($cwd, $timeout, $maxOutput), $denyBinaries);

        $this->allowBinaries = array_values($allowBinaries);
        $this->denyOperators = [';', '|', '&&', '||', '`', '$(', '>', '<'];
        $this->denyFlags = $denyFlags ?? [
            '-r'  => 'php',      // php -r <code>
            '-B'  => 'php',      // php -B <code>
            '-c'  => 'sh*',      // sh/bash -c
            '-e'  => 'node|jq',  // node -e / jq -e? (jq -e 无代码注入,保留给未来精化;node -e 必须)
            '--eval' => 'node',
        ];
        $this->cwd = $cwd;
        $this->timeout = $timeout;
        $this->maxOutput = $maxOutput;
    }

    /** 谱系特例:骨架 = 唯一自由槽 {cmd}(design/25 §1 表第三行) */
    protected function template(): string
    {
        return '{cmd}';
    }

    protected function arguments(): array
    {
        return [
            'cmd' => ['type' => 'string', 'description' => 'Command line to run; the program name must be on the allow-list', 'required' => true],
        ];
    }

    public function name(): string
    {
        return 'exec';
    }

    public function description(): string
    {
        return 'Execute an allowed program inside the runtime scratch directory and return stdout/stderr (truncated).';
    }

    /**
     * 自由槽专属防线(1–4)先行,再走基类通用执行。
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        if ($this->allowBinaries === []) {
            return 'error: exec is disabled (no allowed binaries configured)';
        }

        $cmd = trim((string) ($args['cmd'] ?? ''));
        if ($cmd === '') {
            return 'error: empty command';
        }

        // 防线 3:shell 操作符扫描(防拼接出白名单外的命令)
        foreach ($this->denyOperators as $op) {
            if (strpos($cmd, $op) !== false) {
                return sprintf('error: shell operator "%s" is not allowed (one simple command per call)', $op);
            }
        }

        // 可执行名(第一个 token)
        $parts = preg_split('/\s+/', $cmd) ?: [];
        $binary = basename((string) $parts[0]);

        // 防线 2:兜底黑名单(运行期——基类构造期只管骨架,此处管模型输入)
        if (\in_array($binary, ['rm', 'mv', 'sh', 'bash', 'zsh', 'sudo', 'kill', 'chmod', 'chown', 'dd', 'mkfs', 'shutdown', 'reboot'], true)) {
            return sprintf('error: binary "%s" is denied (use the file tools for sandboxed file operations)', $binary);
        }

        // 防线 1:白名单
        if (!\in_array($binary, $this->allowBinaries, true)) {
            return sprintf('error: binary "%s" is not in the allowed list', $binary);
        }

        // 防线 4:任意代码入口(flag × 适用二进制)
        for ($i = 1, $n = \count($parts); $i < $n; $i++) {
            $token = (string) $parts[$i];
            foreach ($this->denyFlags as $flag => $applies) {
                if ($token !== $flag) {
                    continue;
                }
                foreach (explode('|', (string) $applies) as $pattern) {
                    if ($pattern === '*' || strpos($binary, $pattern) === 0) {
                        return sprintf(
                            'error: "%s %s" executes arbitrary code and is denied (write a script to the runtime dir first, then run it)',
                            $binary,
                            $flag
                        );
                    }
                }
            }
        }

        // 防线 1–4 完成 → 直接走内核(防线 5 已在内核)。
        // 不经基类槽位元字符校验:ExecTool 历史行为允许引号等字符的参数(防线 1–4 已覆盖其威胁面),
        // 收紧属行为变更——设计原则:C1 内核下沉保持行为不变(回归验收线)。
        return (new CommandExecKernel($this->cwd, $this->timeout, $this->maxOutput))->run($cmd);
    }
}
