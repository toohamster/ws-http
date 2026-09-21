<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

use Ws\Http\Request;

/**
 * 完整预设(design/21 §4.2):内建工具集 + 模型目录来源注入。
 *
 * 工具集分级(评审决策 2026-09-20):
 * - 文件工具(Read/Write/List/Delete)走 Sandbox,**绝对**边界,默认含;
 * - HttpGet 需域名白名单(空表=禁用),默认含(安全默认关);
 * - ExecTool(系统命令)默认**不含**——须显式授权;壳经 withExec() 开启,
 *   可信名单(find/grep/sed/cat/jq/php/node 等只读/文本处理集)作为出厂默认,
 *   使用者可加减;rm/mv 等破坏性命令不在信任集——删除走 DeleteFileTool(沙箱内绝对边界)。
 */
final class FullPreset implements Preset
{
    /** @var Sandbox */
    private $sandbox;

    /** @var ModelSourceInterface|null */
    private $modelSource;

    /** @var string[] HttpGetTool 域名白名单 */
    private $httpAllowHosts;

    /** @var Request|null */
    private $http;

    /** @var string[]|null ExecTool 可信名单(null = 不含 ExecTool) */
    private $execAllowBinaries;

    /** @var string|null ExecTool 工作目录(.runtime) */
    private $execCwd;

    /** @var ToolInterface[]|null 代码形态命令工具批量注入(withCommandTools) */
    private $commandTools;

    /**
     * @param string[] $httpAllowHosts
     * @param string[]|null $execAllowBinaries
     * @param ToolInterface[]|null $commandTools
     */
    public function __construct(
        Sandbox $sandbox,
        ?ModelSourceInterface $modelSource = null,
        array $httpAllowHosts = [],
        ?Request $http = null,
        ?array $execAllowBinaries = null,
        ?string $execCwd = null,
        ?array $commandTools = null
    ) {
        $this->sandbox = $sandbox;
        $this->modelSource = $modelSource;
        $this->httpAllowHosts = $httpAllowHosts;
        $this->http = $http;
        $this->execAllowBinaries = $execAllowBinaries;
        $this->execCwd = $execCwd;
        $this->commandTools = $commandTools;
    }

    /**
     * 批量注入代码形态命令工具(design/25 §4.2);描述文件形态由壳经 Loader 装载后注册,不走此处。
     *
     * @param ToolInterface[] $tools
     */
    public function withCommandTools(array $tools): self
    {
        $clone = clone $this;
        $clone->commandTools = $tools;

        return $clone;
    }

    /**
     * 开启系统命令执行(显式授权):传入可信名单(可加减),空名单 = exec 自身禁用形态。
     *
     * @param string[] $allowBinaries
     */
    public function withExec(array $allowBinaries, ?string $cwd = null): self
    {
        $clone = clone $this;
        $clone->execAllowBinaries = $allowBinaries;
        $clone->execCwd = $cwd;

        return $clone;
    }

    /**
     * claude code 同款只读/文本处理集 + php/node(跑 .runtime 草稿区脚本)。
     *
     * @return string[]
     */
    public static function defaultExecBinaries(): array
    {
        return ['find', 'grep', 'sed', 'cat', 'head', 'tail', 'wc', 'sort', 'uniq', 'jq', 'diff', 'php', 'node'];
    }

    public function tools(): array
    {
        $tools = [
            new Tool\ReadFileTool($this->sandbox),
            new Tool\WriteFileTool($this->sandbox),
            new Tool\ListDirTool($this->sandbox),
            new Tool\DeleteFileTool($this->sandbox),
            new Tool\HttpGetTool($this->httpAllowHosts, $this->http),
        ];

        if ($this->execAllowBinaries !== null) {
            $tools[] = new Tool\ExecTool(
                $this->execAllowBinaries,
                $this->execCwd ?? $this->sandbox->root(),
                30,
                2048
            );
        }

        if ($this->commandTools !== null) {
            foreach ($this->commandTools as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    public function modelSource(): ?ModelSourceInterface
    {
        return $this->modelSource;
    }
}
