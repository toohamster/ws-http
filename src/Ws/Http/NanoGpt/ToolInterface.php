<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 工具契约(design/21 §4):组件唯一扩展点。
 *
 * 能力按工具注册,工具内部自管介质(本地文件走 Sandbox;S3/远程 = 自定义 Tool);
 * 内建 4 工具只是出厂默认,不是封闭集合。
 */
interface ToolInterface
{
    /** 唯一名,snake_case:'read_file' */
    public function name(): string;

    /** 给模型看的自然语言描述 */
    public function description(): string;

    /** OpenAI function JSON Schema(parameters 字段) */
    public function jsonSchema(): array;

    /** 执行;返回给模型看的字符串结果(任何异常应转为错误字符串,见 design/21 §3.1) */
    public function execute(array $args): string;
}
