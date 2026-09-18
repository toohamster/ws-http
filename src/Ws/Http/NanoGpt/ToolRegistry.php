<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 工具注册表(design/21 §4):name → ToolInterface,组件扩展点。
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private $tools = [];

    /**
     * @throws AgentException 603 重名
     */
    public function register(ToolInterface $tool): void
    {
        $name = $tool->name();
        if (isset($this->tools[$name])) {
            throw new AgentException(sprintf('Tool "%s" already registered', $name), 603);
        }
        $this->tools[$name] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @throws AgentException 602 未知名
     */
    public function get(string $name): ToolInterface
    {
        if (!isset($this->tools[$name])) {
            throw new AgentException(sprintf('Unknown tool "%s"', $name), 602);
        }

        return $this->tools[$name];
    }

    /**
     * 汇总为 OpenAI tools 数组。
     *
     * @return array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function jsonSchemas(): array
    {
        $schemas = [];
        foreach ($this->tools as $tool) {
            $schemas[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->name(),
                    'description' => $tool->description(),
                    'parameters'  => $tool->jsonSchema(),
                ],
            ];
        }

        return $schemas;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
