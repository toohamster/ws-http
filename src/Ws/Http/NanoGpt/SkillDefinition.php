<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

use Ws\Http\NanoGpt\AgentException;

/**
 * Skill 定义(design/26 §2):skills/*.skill.json 的解析产物——声明式任务级能力包
 * (指令模板 + 参数槽 + 可选 allowedTools)。
 *
 * 校验规则 S1–S4(design/26 §2;装载期违例 → 613,消息含文件名;不发明新码):
 * - S1:name 必填且匹配 ^[a-z][a-z0-9-]*$(跨文件查重在 SkillLoader::loadAll 批量期);
 * - S2:instruction 必填非空;{slot} 占位必须在 arguments 中有定义;未被引用的槽 → 警告(不拒绝);
 * - S3:arguments 槽 schema 与 design/25 §3 同构(type/description/required/default,扁平);
 * - S4:allowedTools 形态必须为数组;装载期不校验工具名存在性(执行期校验)。
 * 执行期:required 槽缺失 → 614(design/26 §2:经命令提示补参,不发请求)。
 */
final class SkillDefinition
{
    /** @var string */
    private $name;

    /** @var string */
    private $description;

    /** @var string 指令模板({slot} 为参数槽) */
    private $instruction;

    /** @var array<string, array{type: string, description: string, required: bool, default: ?string}> */
    private $arguments;

    /** @var string[]|null allowedTools(null = 不限制) */
    private $allowedTools;

    /** @var string 来源文件名(错误定位) */
    private $sourceFile;

    /** @var string[] 非致命警告(S2 未被引用的槽) */
    private $warnings = [];

    /**
     * @param array<string, mixed> $spec 已解析 JSON(loader 负责读文件/JSON 合法性)
     */
    public function __construct(array $spec, string $sourceFile = 'skill.json')
    {
        // S1
        $name = (string) ($spec['name'] ?? '');
        if ($name === '' || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new AgentException(
                sprintf('skill "%s": name must match ^[a-z][a-z0-9-]*$', $sourceFile),
                613
            );
        }

        // S2:instruction 必填
        $instruction = (string) ($spec['instruction'] ?? '');
        if ($instruction === '') {
            throw new AgentException(
                sprintf('skill "%s": instruction is required', $name),
                613
            );
        }

        // S3:arguments 槽 schema(扁平,同 design/25 §3)
        $arguments = [];
        foreach ((array) ($spec['arguments'] ?? []) as $slot => $def) {
            if (!\is_array($def)) {
                throw new AgentException(
                    sprintf('skill "%s": argument "%s" must be an object', $name, (string) $slot),
                    613
                );
            }
            $type = (string) ($def['type'] ?? 'string');
            if (!\in_array($type, ['string', 'number', 'boolean'], true)) {
                throw new AgentException(
                    sprintf('skill "%s": argument "%s" type must be string|number|boolean', $name, (string) $slot),
                    613
                );
            }
            $arguments[(string) $slot] = [
                'type'        => $type,
                'description' => (string) ($def['description'] ?? ''),
                'required'    => !empty($def['required']),
                'default'     => isset($def['default']) ? (string) $def['default'] : null,
            ];
        }

        // S2:{slot} 占位必须有定义;未引用的槽 → 警告(不拒绝)
        preg_match_all('/\{(\w+)\}/', $instruction, $m);
        $referenced = $m[1];
        foreach (array_unique($referenced) as $slot) {
            if (!isset($arguments[$slot])) {
                throw new AgentException(
                    sprintf('skill "%s": template placeholder {%s} has no argument definition', $name, $slot),
                    613
                );
            }
        }
        foreach (array_keys($arguments) as $slot) {
            if (!in_array($slot, $referenced, true)) {
                $this->warnings[] = sprintf('argument "%s" is not referenced by the template', $slot);
            }
        }

        // S4:allowedTools 形态(存在性执行期校验)
        $allowed = $spec['allowedTools'] ?? null;
        if ($allowed !== null) {
            if (!\is_array($allowed)) {
                throw new AgentException(
                    sprintf('skill "%s": allowedTools must be an array', $name),
                    613
                );
            }
            $allowed = array_map('strval', array_values($allowed));
        }

        $this->name = $name;
        $this->description = (string) ($spec['description'] ?? '');
        $this->instruction = $instruction;
        $this->arguments = $arguments;
        $this->allowedTools = $allowed;
        $this->sourceFile = $sourceFile;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function instruction(): string
    {
        return $this->instruction;
    }

    /**
     * @return array<string, array{type: string, description: string, required: bool, default: ?string}>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return string[]|null
     */
    public function allowedTools(): ?array
    {
        return $this->allowedTools;
    }

    public function sourceFile(): string
    {
        return $this->sourceFile;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * 渲染(design/26 §3):{slot} 替换 + [skill: name] 前缀标记;required 缺失 → 614
     * (经命令提示补参,不发请求);default 兜底;未提供的可选槽替换为空串。
     *
     * 参数对位:位置参数按 arguments 声明序;名值对(key:value 经命令解析)直取。
     *
     * @param array<int|string, string> $args
     */
    public function render(array $args): string
    {
        $positional = [];
        $named = [];
        foreach ($args as $k => $v) {
            if (\is_int($k)) {
                $positional[] = (string) $v;
            } else {
                $named[$k] = (string) $v;
            }
        }

        $values = [];
        $posIndex = 0;
        foreach ($this->arguments as $slot => $def) {
            if (array_key_exists($slot, $named)) {
                $values[$slot] = $named[$slot];
            } elseif ($posIndex < \count($positional)) {
                $values[$slot] = $positional[$posIndex];
                $posIndex++;
            } elseif ($def['default'] !== null) {
                $values[$slot] = $def['default'];
            } elseif ($def['required']) {
                throw new AgentException(
                    sprintf('skill "%s": missing required argument "%s" (%s)', $this->name, $slot, $def['description']),
                    614
                );
            } else {
                $values[$slot] = '';
            }
        }

        $text = preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => $values[$m[1]] ?? $m[0],
            $this->instruction
        );

        // 前缀标记(design/26 §3:会话历史中可辨识;auto-compact 摘要可理解)
        return sprintf('[skill: %s] %s', $this->name, $text);
    }

    /**
     * allowedTools 渲染段(design/26 §3:执行期约束 = 提示性,渲染进指令文本;
     * 不做运行期工具注册表裁剪)。未知工具名忽略并提示(S4 执行期校验)。
     *
     * @param string[] $registeredToolNames 当前已注册工具名
     */
    public function allowedToolsClause(array $registeredToolNames): ?string
    {
        if ($this->allowedTools === null) {
            return null;
        }

        $known = [];
        $unknown = [];
        foreach ($this->allowedTools as $toolName) {
            if (in_array($toolName, $registeredToolNames, true)) {
                $known[] = $toolName;
            } else {
                $unknown[] = $toolName; // S4:执行期未知名忽略 + 提示
            }
        }

        if ($known === []) {
            return null; // 全部未知名 → 不加约束段(渲染时提示)
        }

        $clause = sprintf('本次任务只允许使用这些工具: %s。', implode(', ', $known));
        if ($unknown !== []) {
            $clause .= sprintf(' (ignored unknown tools: %s)', implode(', ', $unknown));
        }

        return $clause;
    }
}
