<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 描述文件装载器(design/25 §4):扫描 tools/ 下 *.tool.json → JsonCommandTool 实例。
 *
 * - 与代码形态同源:实例化 JsonCommandTool(→ CommandToolBase 校验),声明式不引入第二条安全实现;
 * - 单文件非法 → AgentException 613(文件名在消息),**跳过该文件继续其余**(不整批失败);
 * - name 冲突 → 613(提示两个文件名);
 * - 发布/分享 = 复制一个 .tool.json(Store 触发条件见 design/25 §1)。
 */
final class DescriptorToolLoader
{
    /** @var string tools 目录(壳根下;--work 变更时随之) */
    private $dir;

    /** @var CommandExecKernel */
    private $kernel;

    public function __construct(string $dir, CommandExecKernel $kernel)
    {
        $this->dir = $dir;
        $this->kernel = $kernel;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AgentException(sprintf('Cannot create tools dir "%s"', $dir), 613);
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * @return ToolInterface[]
     */
    public function loadAll(): array
    {
        $tools = [];
        $names = [];

        foreach (glob($this->dir . '/*.tool.json') ?: [] as $file) {
            try {
                $tool = $this->loadFile((string) $file);
            } catch (AgentException $e) {
                // 单文件非法:跳过继续(不整批失败)——按 design/25 §4;错误不吞:写 sidecar 日志供壳展示
                @file_put_contents($this->dir . '/.loader-errors.log', sprintf("[%s] %s\n", date('c'), $e->getMessage()), FILE_APPEND);
                continue;
            }

            if (isset($names[$tool->name()])) {
                throw new AgentException(
                    sprintf('descriptor name "%s" duplicated (in %s and %s)', $tool->name(), $names[$tool->name()], basename((string) $file)),
                    613
                );
            }
            $names[$tool->name()] = basename((string) $file);
            $tools[] = $tool;
        }

        return $tools;
    }

    public function loadFile(string $file): JsonCommandTool
    {
        $raw = @\file_get_contents($file);
        if ($raw === false) {
            throw new AgentException(sprintf('cannot read descriptor "%s"', $file), 613);
        }

        $spec = json_decode($raw, true);
        if (!\is_array($spec)) {
            throw new AgentException(sprintf('descriptor "%s" is not valid JSON', basename($file)), 613);
        }

        // 结构预检(semantic 校验在 JsonCommandTool/CommandToolBase)
        foreach (['name', 'command', 'description'] as $required) {
            if (!isset($spec[$required]) || (string) $spec[$required] === '') {
                throw new AgentException(sprintf('descriptor "%s": missing "%s"', basename($file), $required), 613);
            }
        }

        return new JsonCommandTool($this->kernel, $spec, basename($file));
    }
}
