<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\ToolInterface;

/**
 * 系统命令执行(design/21 §8 评审决策):草稿区"建脚本→执行→存档"链的执行环节。
 *
 * 五层防线(评审决策 2026-09-20,可信集 = claude code 同款只读/文本处理 + php/node):
 * 1. 二进制白名单(rm/mv/sh/sudo 等不在名单即拒);
 * 2. 兜底黑名单(即使白名单被扩,破坏性命令仍拒);
 * 3. shell 操作符扫描(; | && || ` $( ) > < 防拼接出白名单外的命令);
 * 4. 任意代码入口拒绝(php -r / -B,sh -c,node -e 等——要跑代码只能先 write_file 到草稿区再执行脚本文件);
 * 5. cwd 锁定 + 超时 proc_terminate + 输出截断。
 *
 * 诚实边界:防线 1–4 是"尽力保证"(进程级文件系统隔离纯 PHP 做不到绝对);
 * 文件读写删走内建文件工具(Sandbox 绝对边界),exec 仅用于运行草稿区脚本。
 * FullPreset 默认不含本工具——经 withExec() 显式授权(名单可加减)。
 */
final class ExecTool implements ToolInterface
{
    /** @var string[] 允许的可执行名;空表 = 禁用 */
    private $allowBinaries;

    /** @var string[] 兜底黑名单(破坏性命令) */
    private $denyBinaries;

    /** @var string[] 禁止的 shell 操作符 */
    private $denyOperators;

    /** @var array<string, string> 任意代码入口:[flag => 适用二进制(前缀匹配, '*' = 全部)] */
    private $denyFlags;

    /** @var string 命令工作目录(草稿区绝对路径) */
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
        $this->allowBinaries = array_values($allowBinaries);
        $this->denyBinaries = $denyBinaries ?? ['rm', 'mv', 'sh', 'bash', 'zsh', 'sudo', 'kill', 'chmod', 'chown', 'dd', 'mkfs', 'shutdown', 'reboot'];
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

    public function name(): string
    {
        return 'exec';
    }

    public function description(): string
    {
        return 'Execute an allowed program inside the runtime scratch directory and return stdout/stderr (truncated).';
    }

    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'cmd' => ['type' => 'string', 'description' => 'Command line to run; the program name must be on the allow-list'],
            ],
            'required'   => ['cmd'],
        ];
    }

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

        // 防线 2:兜底黑名单
        if (\in_array($binary, $this->denyBinaries, true)) {
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

        if (!\function_exists('proc_open')) {
            return 'error: proc_open is not available';
        }

        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->cwd
        );

        if (!\is_resource($process)) {
            return 'error: cannot spawn process';
        }

        $output = '';
        $timedOut = false;
        $started = microtime(true);
        $exitCode = -1;

        while (true) {
            $status = proc_get_status($process);
            $readable = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($readable, $write, $except, 0, 200000) > 0) {
                foreach ($readable as $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                    }
                }
            }

            if (!$status['running']) {
                // drain 到 EOF(exitcode 在 running→false 转变后的一次 status 里才有效)
                $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) - $started >= $this->timeout) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            return sprintf('error: timed out after %ds; partial output: %s', $this->timeout, mb_substr($output, 0, $this->maxOutput));
        }

        return sprintf("exit: %d\noutput: %s", $exitCode, mb_substr(trim($output), 0, $this->maxOutput));
    }
}
