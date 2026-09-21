<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 命令执行内核(design/25 §2):无 ToolInterface 的纯执行体,谱系全形态复用。
 *
 * 单一职责:把一条**已通过上游校验**的简单命令跑完(不再审——防线归属见 design/25 §5)。
 * 行为与 design/21 §8 ExecTool 原执行体一致(proof:ExecToolTest 全量回归,C1 验收线)。
 */
final class CommandExecKernel
{
    /** @var string 命令工作目录(草稿区绝对路径) */
    private $cwd;

    /** @var int 超时秒 */
    private $timeout;

    /** @var int 输出截断长度 */
    private $maxOutput;

    public function __construct(string $cwd, int $timeout = 30, int $maxOutput = 2048)
    {
        $this->cwd = $cwd;
        $this->timeout = $timeout;
        $this->maxOutput = $maxOutput;
    }

    /**
     * @param string $command 已校验的简单命令(无操作符)
     */
    public function run(string $command): string
    {
        if ($command === '') {
            return 'error: empty command';
        }

        if (!\function_exists('proc_open')) {
            return 'error: proc_open is not available';
        }

        $process = proc_open(
            $command,
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
                // drain 到 EOF(exitcode 在 running→false 转变后的一次 status 里才有效,PHP 7.4)
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
