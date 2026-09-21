<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 声明式命令工具(design/25 §4):描述文件(.tool.json)的 CommandToolBase 实现——
 * 与代码形态同源校验(槽位元字符/骨架规则走同一基类),声明式不引入第二条安全实现。
 */
final class JsonCommandTool extends Tool\CommandToolBase
{
    /** @var string */
    private $toolName;

    /** @var string */
    private $toolDescription;

    /** @var string 模板 */
    private $template;

    /** @var array<string, array{type: string, description: string, required?: bool, default?: string}> */
    private $arguments;

    /** @var string 描述文件名(错误消息定位) */
    private $sourceFile;

    /**
     * @param array<string, mixed> $spec 已解析的描述文件内容(loader 负责结构校验)
     */
    public function __construct(CommandExecKernel $kernel, array $spec, string $sourceFile = 'tool.json')
    {
        $this->toolName = (string) ($spec['name'] ?? '');
        $this->toolDescription = (string) ($spec['description'] ?? '');
        $this->template = (string) ($spec['command'] ?? '');
        $this->arguments = [];
        foreach ((array) ($spec['arguments'] ?? []) as $slot => $def) {
            if (!\is_array($def)) {
                throw new AgentException(sprintf('descriptor "%s": argument "%s" must be an object', $sourceFile, $slot), 613);
            }
            $this->arguments[(string) $slot] = [
                'type'        => (string) ($def['type'] ?? 'string'),
                'description' => (string) ($def['description'] ?? ''),
                'required'    => !empty($def['required']),
                'default'     => isset($def['default']) ? (string) $def['default'] : null,
            ];
        }
        $this->sourceFile = $sourceFile;

        parent::__construct($kernel); // 骨架/黑名单校验(613)
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    protected function template(): string
    {
        return $this->template;
    }

    protected function arguments(): array
    {
        return $this->arguments;
    }

    public function sourceFile(): string
    {
        return $this->sourceFile;
    }
}
