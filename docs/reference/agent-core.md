[English](./agent-core.md) | [中文版本](./zh-CN/agent-core.zh-CN.md)

# NanoGpt — Agent Core

> Module: `Ws\Http\NanoGpt` — `Agent`, `Conversation`, `ToolInterface`, `ToolRegistry`, `Sandbox`, `Tool\*` (built-in tools), `AgentException`
> Transport is pluggable — see [agent-transports.md](agent-transports.md)

## Overview

NanoGpt is a **protocol-agnostic agent orchestration layer**: an agent loop, a tool protocol, a sandbox, and session state — all independent of any LLM vendor. The loop state machine:

```
user input → transport (messages + tools) → response → tool_calls? → execute each → backfill role=tool → loop
                                                     → text → yield & finish (or maxTurns → 601)
```

The orchestration layer contains **zero vendor names**. Everything provider-specific lives behind `ChatTransportInterface` (see [agent-transports.md](agent-transports.md)).

## Key Components

### `Agent`

```php
public const DEFAULT_MAX_TURNS = 8;

public function __construct(
    Transport\ChatTransportInterface $transport,   // the only transport dependency
    string $model,                                 // per-request model id (options['model'])
    int $maxTurns = self::DEFAULT_MAX_TURNS,
    ?Conversation $conversation = null
)
public function tools(): ToolRegistry
public function conversation(): Conversation

/**
 * @return \Generator<int, array<string, mixed>> event stream:
 *   ['type' => 'text',        'text' => string]
 *   ['type' => 'tool_call',   'name' => string, 'args' => array]
 *   ['type' => 'tool_result', 'name' => string, 'result' => string]
 *   ['type' => 'error',       'message' => string, 'code' => int]
 * @throws AgentException 601 (max turns exhausted without text)
 */
public function run(string $userInput): \Generator;
```

Behavioral guarantees:

- **Transport failures never destroy the session**: classified errors (620–624) are yielded as `error` events; the user message stays in the conversation, so calling `run()` again retries the same turn;
- **Tool exceptions don't abort the loop**: the error string is backfilled as the tool result so the model can self-correct;
- **Unknown tool names** are backfilled as `error: unknown tool "x"` (not fatal);
- `tools` schemas are sent only when non-empty; `model` travels as a request-level option (vendor default remains the transport's fallback).

### `Conversation`

```php
public function __construct(?array $system = null)   // role=system preamble (not in messages())
public function append(array $message): void         // wire-format message
public function messages(): array
public function forRequest(): array                  // [system?] + messages
public function accumulateUsage(object $usage): void // cross-turn token totals
public function usage(): array                       // {prompt_tokens, completion_tokens, total_tokens}
public function reset(): void
public function restoreUsage(array $usage): void
public function setSystem(?array $system): void      // runtime-effective (memory injection)
public function truncateFrom(int $messageIndex): void // user-boundary only → 605 otherwise
public function userCheckpoints(int $prefixWidth = 40): array  // rewind menu data
public function snapshot(): array                    // ['v' => 1, messages, usage, system]
public static function restore(array $snapshot): self // unknown version → 611
```

### Tool protocol

```php
interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function jsonSchema(): array;   // JSON Schema for the arguments object
    public function execute(array $args): string;   // result string backfilled to the model
}

final class ToolRegistry
{
    public function register(ToolInterface $tool): void  // duplicate name → 603
    public function has(string $name): bool
    public function get(string $name): ToolInterface
    public function jsonSchemas(): array                 // OpenAI tools array
    public function names(): array
}
```

### `Sandbox` (security boundary)

```php
public function __construct(string $root, array $extraRoots = [])
public function resolve(string $userPath): string  // realpath-prefixed containment check → 610 on escape
public function root(): string
```

All built-in file tools route through one sandbox: absolute boundary, traversal-proof (`../` escapes rejected).

### Built-in tools (`NanoGpt\Tool`)

| Tool | Name | Purpose |
|---|---|---|
| `ReadFileTool` / `WriteFileTool` / `ListDirTool` / `DeleteFileTool` | `read_file` `write_file` `list_dir` `delete_file` | File ops inside the sandbox |
| `ExecTool` | `exec` | System commands, allow-list gated |
| `GrepTool` / `FindTool` / `SedPrintTool` | `grep` / `find` / `sed_print` | Text search / file lookup / non-destructive sed printing |
| `CommandToolBase` + `JsonCommandTool` | (declarative) | `.tool.json` descriptors → shell command tools |
| `HttpToolBase` family | `http_get` `api_test` `api_fetch` `api_json_post` | Network tools with host allow-list (empty list = disabled) |

## Usage Examples

### Minimal agent with a custom tool

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

### File tools inside a sandbox

```php
use Ws\Http\NanoGpt\{Agent, Sandbox};
use Ws\Http\NanoGpt\Tool\ReadFileTool;
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;

$agent = new Agent(new DeepSeekTransport(['api_key' => $apiKey]), 'deepseek-chat');
$agent->tools()->register(new ReadFileTool(new Sandbox('/abs/path/workspace')));

foreach ($agent->run('Read summary.md and count the headings') as $event) { /* ... */ }

$agent->conversation()->usage();  // cross-turn totals
```

### Error event with classification code

```php
foreach ($agent->run('hi') as $event) {
    if ($event['type'] === 'error' && $event['code'] === 621) {
        // rate-limited — wait or hint compaction; conversation intact for retry
    }
}
```

## Configuration

- **`maxTurns`** (default 8) — loop upper bound; exceeding it throws 601.
- **`model`** — sent per request; empty/absent falls back to the transport's vendor default.
- **`Sandbox::extraRoots`** — additional readable roots (e.g. a drafts directory).

## Dependencies

- **Depends on**: `Transport\ChatTransportInterface` (only transport seam — no vendor, no HTTP in this layer).
- **Depended on by**: `examples/cc-gpt` shell (first consumer), any embedding application.

Design details: [design/21-NanoGpt设计.md](../../design/21-NanoGpt设计.md), [design/25-工具谱系与声明式工具.md](../../design/25-工具谱系与声明式工具.md).
