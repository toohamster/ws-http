<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\CommandExecKernel;
use Ws\Http\NanoGpt\ToolInterface;

/**
 * 命令工具抽象基类(design/25 §3):代码形态的谱系端点——骨架写死、参数槽受约束。
 *
 * 安全模型(谱系原则:安全强度 ∝ 模板自由度):
 * - 骨架(template)由子类写死,模型无法表达骨架外内容——结构性安全;
 * - 模型只能填参数槽(jsonSchema 自动生成;元字符拒绝 + enum 值域校验);
 * - 骨架首 token = 骨架二进制,构造期校验(元字符/兜底黑名单)——类定义期安全。
 */
abstract class CommandToolBase implements ToolInterface
{
    /** @var CommandExecKernel */
    private $kernel;

    /** @var string 骨架二进制(首 token,构造期提取) */
    private $binary;

    /** @var string[] 兜底黑名单(谱系贯穿,构造期拦截) */
    private $denyBinaries;

    /** @var string[] 参数槽值禁止的元字符(单槽位无法逃逸出骨架) */
    private const SLOT_DENY_CHARS = [';', '|', '&', '$', '`', '>', '<', "\n", "\r", '"', "'", '\\'];

    /**
     * @param string[]|null $denyBinaries null = 默认黑名单(design/25 §5 防线 2 贯穿)
     */
    public function __construct(CommandExecKernel $kernel, ?array $denyBinaries = null)
    {
        $this->kernel = $kernel;
        $this->denyBinaries = $denyBinaries ?? ['rm', 'mv', 'sh', 'bash', 'zsh', 'sudo', 'kill', 'chmod', 'chown', 'dd', 'mkfs', 'shutdown', 'reboot'];

        // 骨架首 token:构造期提取 + 校验(类定义期安全)
        $parts = preg_split('/\s+/', trim($this->template())) ?: [];
        $this->binary = basename((string) ($parts[0] ?? ''));

        if ($this->binary === '') {
            throw new AgentException(sprintf('CommandTool "%s" has an empty template', $this->name()), 613);
        }

        // 骨架中只允许 {slot} 占位与字面量;字面量部分不得含操作符(骨架是代码,不该有)
        $skeleton = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '', trim($this->template())) ?? '';
        foreach ([';', '|', '&&', '`', '$(', '>'] as $op) {
            if (strpos($skeleton, $op) !== false) {
                throw new AgentException(
                    sprintf('CommandTool "%s" template contains shell operator "%s" (skeleton must be a single simple command)', $this->name(), $op),
                    613
                );
            }
        }

        // 防线 2 贯穿:骨架二进制命中兜底黑名单 → 构造期即拒(613)
        if (\in_array($this->binary, $this->denyBinaries, true)) {
            throw new AgentException(
                sprintf('CommandTool "%s" skeleton binary "%s" is denied', $this->name(), $this->binary),
                613
            );
        }
    }

    /** 命令骨架:{slot} 占位;首 token 即骨架二进制 */
    abstract protected function template(): string;

    /**
     * 参数槽定义(模型只能填这些):
     *
     * @return array<string, array{type: string, description: string, required?: bool, default?: string, enum?: string[]}>
     */
    abstract protected function arguments(): array;

    /**
     * jsonSchema 自动生成(design/25 §3)。
     */
    public function jsonSchema(): array
    {
        $properties = [];
        $required = [];
        foreach ($this->arguments() as $slot => $def) {
            $prop = ['type' => (string) ($def['type'] ?? 'string'), 'description' => (string) ($def['description'] ?? '')];
            if (isset($def['enum'])) {
                $prop['enum'] = array_values((array) $def['enum']);
            }
            $properties[$slot] = $prop;
            if (!empty($def['required'])) {
                $required[] = $slot;
            }
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => $required,
        ];
    }

    /**
     * 槽位校验 → 骨架拼装 → 内核执行(design/25 §3 四步)。
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        $values = [];

        // 1. required 校验缺失
        foreach ($this->arguments() as $slot => $def) {
            $provided = isset($args[$slot]) && (string) $args[$slot] !== '';

            if (empty($def['required']) && !$provided) {
                $values[$slot] = (string) ($def['default'] ?? '');
                continue;
            }

            if (!$provided) {
                return sprintf('error: missing required argument "%s"', $slot);
            }

            $value = (string) $args[$slot];

            // 2. 槽位值元字符拒绝
            foreach (self::SLOT_DENY_CHARS as $char) {
                if (strpos($value, $char) !== false) {
                    return sprintf('error: argument "%s" contains forbidden character (put such values in a file first, then reference the path)', $slot);
                }
            }

            // 3. enum 值域校验
            if (isset($def['enum']) && !\in_array($value, array_values((array) $def['enum']), true)) {
                return sprintf('error: argument "%s" must be one of: %s', $slot, implode(', ', (array) $def['enum']));
            }

            $values[$slot] = $value;
        }

        // 4. 骨架 + 槽位值拼装 → 内核
        $command = trim($this->template());
        foreach ($values as $slot => $value) {
            $command = str_replace('{' . $slot . '}', $value, $command);
        }

        return $this->kernel->run($command);
    }
}
