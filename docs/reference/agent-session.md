[English](./agent-session.md) | [中文版本](./zh-CN/agent-session.zh-CN.md)

# NanoGpt — Session, Memory & Skills

> Module: `Ws\Http\NanoGpt` — `ConversationStoreInterface`, `FileConversationStore`, `ContextBudget`, `CompactorInterface`, `LlmCompactor`, `Memory`, `SkillLoader`, `SkillDefinition`

## Overview

Four facilities that make the agent usable across turns, sessions, and days:

1. **Session persistence** — snapshot/restore a `Conversation` to disk (multi-session CLI usage);
2. **Context budgeting & compaction** — conservative token estimation, an 80% threshold, and LLM-backed history summarization so long sessions survive model windows;
3. **Long-term memory** — explicitly written facts injected into the system prompt;
4. **Skills** — declarative task-level capability packs (`*.skill.json`) rendered into a user message.

## Key Components

### Session persistence

```php
interface ConversationStoreInterface
{
    public function save(string $sessionId, array $snapshot): void;
    public function load(string $sessionId): ?array;
    public function list(): array;    // newest first (mtime)
    public function delete(string $sessionId): void;
}

final class FileConversationStore implements ConversationStoreInterface
{
    public function __construct(string $dir);   // one JSON file per session; corrupted files tolerated on load
}
```

Snapshots are `Conversation::snapshot()` (versioned `v: 1`) and restored with `Conversation::restore($snapshot)` (unknown version → 611, guiding a fresh session).

### Context budget & compaction

```php
final class ContextBudget
{
    public function __construct(int $contextWindow, int $reserveForOutput = 4096)
    public function estimateTokens(array $messages): int   // ceil(UTF-8 bytes / 3) — conservative upper bound
    public function needsCompact(array $messages): bool    // estimate ≥ 80% of (window − reserve)
    public function usageRatio(array $messages): float     // 0–1 (for display)
}

interface CompactorInterface
{
    /**
     * @param array<int, array<string, mixed>> $messages the segment being summarized
     * @return array<string, mixed> the summary message (role=user, 【历史摘要】 prefix)
     * @throws AgentException 612 on failure (original messages untouched — compute-then-swap)
     */
    public function compact(array $messages): array;
}

final class LlmCompactor implements CompactorInterface
{
    public function __construct(ChatTransportInterface $transport, string $model, int $summaryMaxTokens = 1024);
}
```

Anti-deadlock guard: `role=tool` messages are pre-clamped to 2000 chars in the summarization prompt only (original messages untouched), preventing "over-limit → compact → the compaction request itself over-limits" loops.

### Long-term memory

```php
final class Memory
{
    public function __construct(string $file, int $maxEntries = 100);  // JSON file; oldest evicted
    public function remember(string $text, array $tags = []): void;    // explicit writes only
    public function recall(?string $tag = null): array;
    public function forget(int $index): void;                          // 1-based
    public function systemPromptBlock(): ?string;                      // block, newest 10
}
```

Injection point: the shell merges `systemPromptBlock()` into `Conversation::setSystem()` — memory lives in **system**, skill invocations live in **user** messages; the two never collide.

### Skills (declarative capability packs)

A skill is a JSON file in `skills/`:

```jsonc
{
  "name": "json-report",                          // S1: ^[a-z][a-z0-9-]*$, unique across files (613)
  "description": "Analyze a JSON file and produce a structured report",
  "instruction": "Analyze {path}: read it with read_file, then output ... Do not modify files.",
  "arguments": {
    "path": { "type": "string", "description": "JSON file path", "required": true }
  },
  "allowedTools": ["read_file", "list_dir"]        // optional; advisory only (rendered into the instruction)
}
```

```php
final class SkillLoader
{
    public function __construct(string $dir);       // missing dir = zero skills (no auto-create)
    public function loadAll(): array;               // name => SkillDefinition; bad file skipped + sidecar log; dup → 613
}

final class SkillDefinition
{
    // Validation S1–S4: load-time violations → 613; missing required arg at render time → 614 (no request sent)
    public function render(array $args): string;    // slot substitution + "[skill: name] " prefix marker
    public function allowedToolsClause(array $registeredToolNames): ?string; // advisory clause; unknown names ignored+reported
}
```

Injection point is a deliberate design decision: the rendered skill becomes **this turn's user message** — it flows through history naturally, so persistence, compaction, and rewind need zero special handling (and it never conflicts with `Memory`'s system-prompt injection).

## Usage Examples

### Session save / restore

```php
use Ws\Http\NanoGpt\{FileConversationStore, Conversation};

$store = new FileConversationStore('.runtime/sessions');
$store->save('20261027-090000', $agent->conversation()->snapshot());

// Later (or in a new process):
$snapshot = $store->load('20261027-090000');
$conversation = Conversation::restore($snapshot);
$agent = new Agent($transport, 'deepseek-chat', 8, $conversation);
```

### Budget-driven auto-compaction (shell behavior)

```php
use Ws\Http\NanoGpt\{ContextBudget, LlmCompactor};

$budget = new ContextBudget(65536);
if ($budget->needsCompact($agent->conversation()->messages())) {
    $summary = (new LlmCompactor($transport, 'deepseek-chat'))->compact(
        array_slice($agent->conversation()->messages(), 0, -4)  // keep last 2 turns
    );
    $agent->conversation()->reset();
    $agent->conversation()->append($summary);
    // usage totals preserved via restoreUsage() — cost accounting is separate from window usage
}
```

### Long-term memory

```php
use Ws\Http\NanoGpt\Memory;

$memory = new Memory('.work/memory.json');
$memory->remember('User prefers PHP 7.4 strict types');
$memory->remember('Deploys happen on Thursdays', ['ops']);

// Before the next turn:
$base = $agent->conversation()->system();
$block = $memory->systemPromptBlock();
$agent->conversation()->setSystem(/* base + "\n\n" + $block */);
```

### Skills

```php
use Ws\Http\NanoGpt\SkillLoader;

$skills = (new SkillLoader('skills/'))->loadAll();

$definition = $skills['json-report'];
$message = $definition->render(['data.json'])                       // positional: first declared slot
        . $definition->allowedToolsClause($agent->tools()->names());

foreach ($agent->run($message) as $event) { /* normal event stream */ }
// The "[skill: json-report] ..." user message persists in history like any other turn.
```

## Configuration

| Item | Where | Default |
|---|---|---|
| Session dir | store constructor | `.runtime/sessions` (shell) |
| Snapshot version | `Conversation::snapshot()` | `v: 1` |
| Budget reserve | `ContextBudget::__construct` | 4096 tokens |
| Compaction threshold | `needsCompact()` | 80% of (window − reserve) |
| Tool-result clamp | `LlmCompactor` | 2000 chars (prompt only) |
| Memory capacity | `Memory::__construct` | 100 entries |
| Skill naming | S1 | `^[a-z][a-z0-9-]*$` |

## Dependencies

- **Depends on**: `Conversation` (snapshot subject), `ChatTransportInterface` (LlmCompactor makes its own LLM call through the same transport), `AgentException` code range 610–614.
- **Depended on by**: `examples/cc-gpt` shell (`/compact` `/rewind` `/clear` `/resume` `/memory` `/skill` commands, auto-compaction before each turn).

Design details: [design/24-会话与记忆设计.md](../../design/24-会话与记忆设计.md), [design/26-Skill设计.md](../../design/26-Skill设计.md).
