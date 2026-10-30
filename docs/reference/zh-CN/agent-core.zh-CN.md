[English](../agent-core.md) | [中文版本](./agent-core.zh-CN.md)

# NanoGpt — Agent 核心

> 模块:`Ws\Http\NanoGpt` — `Agent`、`Conversation`、`ToolInterface`、`ToolRegistry`、`Sandbox`、`Tool\*`(内建工具)、`AgentException`
> 传输可插拔——见 [agent-transports.zh-CN.md](./agent-transports.zh-CN.md)

## Overview

NanoGpt 是**协议无关的 agent 编排层**:agent 循环、工具协议、沙箱、会话状态——全部独立于任何 LLM 厂商。循环状态机:

```
用户输入 → 传输(消息 + 工具)→ 响应 → 有 tool_calls?→ 逐个执行 → 回填 role=tool → 继续循环
                                        → 文本 → yield 并结束(或超 maxTurns → 601)
```

编排层源码**零厂商名**。一切厂商相关都活在 `ChatTransportInterface` 之后(见 [agent-transports.zh-CN.md](./agent-transports.zh-CN.md))。

## Key Components

### `Agent`

```php
public const DEFAULT_MAX_TURNS = 8;

public function __construct(
    Transport\ChatTransportInterface $transport,   // 唯一的传输依赖
    string $model,                                 // 每请求模型 id(options['model'])
    int $maxTurns = self::DEFAULT_MAX_TURNS,
    ?Conversation $conversation = null
)
public function tools(): ToolRegistry
public function conversation(): Conversation

/**
 * @return \Generator<int, array<string, mixed>> 事件流:
 *   ['type' => 'text',        'text' => string]
 *   ['type' => 'tool_call',   'name' => string, 'args' => array]
 *   ['type' => 'tool_result', 'name' => string, 'result' => string]
 *   ['type' => 'error',       'message' => string, 'code' => int]
 * @throws AgentException 601(超 maxTurns 未产出文本)
 */
public function run(string $userInput): \Generator;
```

行为保证:

- **传输失败不毁会话**:分类错误(620–624)以 `error` 事件产出;用户消息留在会话中,再次 `run()` 即重试同一轮;
- **工具异常不中断循环**:错误字符串作为工具结果回填,模型可自行纠正;
- **未知名工具**回填 `error: unknown tool "x"`(非致命);
- `tools` schemas 仅在非空时发送;`model` 以请求级选项传递(厂商默认仍是传输层的兜底)。

### `Conversation`

```php
public function __construct(?array $system = null)   // role=system 前言(不进 messages())
public function append(array $message): void         // wire 格式消息
public function messages(): array
public function forRequest(): array                  // [system?] + messages
public function accumulateUsage(object $usage): void // 跨轮 token 累计
public function usage(): array                       // {prompt_tokens, completion_tokens, total_tokens}
public function reset(): void
public function restoreUsage(array $usage): void
public function setSystem(?array $system): void      // 运行时生效(记忆注入)
public function truncateFrom(int $messageIndex): void // 仅 user 边界 → 否则 605
public function userCheckpoints(int $prefixWidth = 40): array  // rewind 菜单数据
public function snapshot(): array                    // ['v' => 1, messages, usage, system]
public static function restore(array $snapshot): self // 未知版本 → 611
```

### 工具协议

```php
interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function jsonSchema(): array;   // 参数对象的 JSON Schema
    public function execute(array $args): string;   // 结果字符串回填给模型
}

final class ToolRegistry
{
    public function register(ToolInterface $tool): void  // 重名 → 603
    public function has(string $name): bool
    public function get(string $name): ToolInterface
    public function jsonSchemas(): array                 // OpenAI tools 数组
    public function names(): array
}
```

### `Sandbox`(安全边界)

```php
public function __construct(string $root, array $extraRoots = [])
public function resolve(string $userPath): string  // realpath 前缀包含校验 → 越界抛 610
public function root(): string
```

所有内建文件工具走同一个沙箱:绝对边界,防穿越(`../` 逃逸被拒)。

### 内建工具(`NanoGpt\Tool`)

| 工具 | 名字 | 用途 |
|---|---|---|
| `ReadFileTool` / `WriteFileTool` / `ListDirTool` / `DeleteFileTool` | `read_file` `write_file` `list_dir` `delete_file` | 沙箱内文件操作 |
| `ExecTool` | `exec` | 系统命令,白名单门控 |
| `GrepTool` / `FindTool` / `SedPrintTool` | `grep` / `find` / `sed_print` | 文本搜索 / 文件查找 / 非破坏性 sed 打印 |
| `CommandToolBase` + `JsonCommandTool` | (声明式) | `.tool.json` 描述文件 → shell 命令工具 |
| `HttpToolBase` 族 | `http_get` `api_test` `api_fetch` `api_json_post` | 网络工具,host 白名单(空表 = 禁用) |

## Usage Examples

### 最小 agent + 自定义工具

```php
use Ws\Http\NanoGpt\{Agent, ToolInterface};
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;

final class EchoTool implements ToolInterface
{
    public function name(): string { return 'echo'; }
    public function description(): string { return 'Echo the given text back.'; }
    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ];
    }
    public function execute(array $args): string
    {
        return 'echo: ' . (string) ($args['text'] ?? '');
    }
}

$agent = new Agent(
    new DeepSeekTransport(['api_key' => $apiKey]),
    'deepseek-chat'
);
$agent->tools()->register(new EchoTool());

foreach ($agent->run('Use the echo tool with text "hello"') as $event) {
    switch ($event['type']) {
        case 'tool_call':   echo '⚙ ', $event['name'], ' ', json_encode($event['args']), PHP_EOL; break;
        case 'tool_result': echo '  ↳ ', $event['result'], PHP_EOL; break;
        case 'text':        echo $event['text'], PHP_EOL; break;
        case 'error':       echo '! [', $event['code'], '] ', $event['message'], PHP_EOL; break;
    }
}
```

### 沙箱内的文件工具

```php
use Ws\Http\NanoGpt\{Agent, Sandbox};
use Ws\Http\NanoGpt\Tool\ReadFileTool;
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;

$agent = new Agent(new DeepSeekTransport(['api_key' => $apiKey]), 'deepseek-chat');
$agent->tools()->register(new ReadFileTool(new Sandbox('/abs/path/workspace')));

foreach ($agent->run('Read summary.md and count the headings') as $event) { /* ... */ }

$agent->conversation()->usage();  // 跨轮累计
```

### 带分类码的错误事件

```php
foreach ($agent->run('hi') as $event) {
    if ($event['type'] === 'error' && $event['code'] === 621) {
        // 被限流——等待或提示压缩;会话完好,可重试
    }
}
```

## Configuration

- **`maxTurns`**(默认 8)— 循环上限;超出抛 601;
- **`model`** — 每请求发送;空/缺省回落传输层厂商默认;
- **`Sandbox::extraRoots`** — 额外可读根(如草稿目录)。

## Dependencies

- **依赖**:`Transport\ChatTransportInterface`(唯一传输缝隙——本层无厂商、无 HTTP)。
- **被依赖**:`examples/cc-gpt` 壳(首个消费方)、任意嵌入应用。

设计详情:[design/21-NanoGpt设计.md](../../../design/21-NanoGpt设计.md)、[design/25-工具谱系与声明式工具.md](../../../design/25-工具谱系与声明式工具.md)。
