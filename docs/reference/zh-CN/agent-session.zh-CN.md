[English](../agent-session.md) | [中文版本](./agent-session.zh-CN.md)

# NanoGpt — 会话、记忆与技能

> 模块:`Ws\Http\NanoGpt` — `ConversationStoreInterface`、`FileConversationStore`、`ContextBudget`、`CompactorInterface`、`LlmCompactor`、`Memory`、`SkillLoader`、`SkillDefinition`

## Overview

四项设施让 agent 跨轮次、跨会话、跨天数可用:

1. **会话持久化** — `Conversation` 快照/恢复到磁盘(多会话 CLI 用法);
2. **上下文预算与压缩** — 保守 token 估算、80% 阈值、LLM 驱动的历史摘要,让长会话活过模型窗口;
3. **长期记忆** — 显式写入的事实,注入 system 提示;
4. **技能** — 声明式任务级能力包(`*.skill.json`),渲染为本轮 user 消息。

## Key Components

### 会话持久化

```php
interface ConversationStoreInterface
{
    public function save(string $sessionId, array $snapshot): void;
    public function load(string $sessionId): ?array;
    public function list(): array;    // 最新在前(mtime)
    public function delete(string $sessionId): void;
}

final class FileConversationStore implements ConversationStoreInterface
{
    public function __construct(string $dir);   // 每会话一个 JSON 文件;损坏文件加载时容忍
}
```

快照是 `Conversation::snapshot()`(版本 `v: 1`),用 `Conversation::restore($snapshot)` 恢复(未知版本 → 611,引导开新会话)。

### 上下文预算与压缩

```php
final class ContextBudget
{
    public function __construct(int $contextWindow, int $reserveForOutput = 4096)
    public function estimateTokens(array $messages): int   // ceil(UTF-8 字节数 / 3) — 保守上界
    public function needsCompact(array $messages): bool    // 估算 ≥ (窗口 − 预留) 的 80%
    public function usageRatio(array $messages): float     // 0–1(展示用)
}

interface CompactorInterface
{
    /**
     * @param array<int, array<string, mixed>> $messages 被摘要的段
     * @return array<string, mixed> 摘要消息(role=user,【历史摘要】前缀)
     * @throws AgentException 612 失败时(原 messages 不动——先算后换)
     */
    public function compact(array $messages): array;
}

final class LlmCompactor implements CompactorInterface
{
    public function __construct(ChatTransportInterface $transport, string $model, int $summaryMaxTokens = 1024);
}
```

防死锁护栏:`role=tool` 消息在进入摘要 prompt 前截断到 2000 字符(仅影响摘要输入,原消息不动),阻断"超限 → 压缩 → 压缩请求自身超限"循环。

### 长期记忆

```php
final class Memory
{
    public function __construct(string $file, int $maxEntries = 100);  // JSON 文件;最旧被驱逐
    public function remember(string $text, array $tags = []): void;    // 仅显式写入
    public function recall(?string $tag = null): array;
    public function forget(int $index): void;                          // 1-based
    public function systemPromptBlock(): ?string;                      // 块,最新 10 条
}
```

注入点:壳把 `systemPromptBlock()` 合并进 `Conversation::setSystem()`——记忆住在 **system**,技能调用住在 **user** 消息;两者永不冲突。

### 技能(声明式能力包)

技能是 `skills/` 下的 JSON 文件:

```jsonc
{
  "name": "json-report",                          // S1: ^[a-z][a-z0-9-]*$,跨文件唯一(613)
  "description": "Analyze a JSON file and produce a structured report",
  "instruction": "Analyze {path}: read it with read_file, then output ... Do not modify files.",
  "arguments": {
    "path": { "type": "string", "description": "JSON file path", "required": true }
  },
  "allowedTools": ["read_file", "list_dir"]        // 可选;仅建议性(渲染进指令文本)
}
```

```php
final class SkillLoader
{
    public function __construct(string $dir);       // 目录不存在 = 零技能(不自动创建)
    public function loadAll(): array;               // name => SkillDefinition;坏文件跳过 + sidecar 日志;重名 → 613
}

final class SkillDefinition
{
    // 校验 S1–S4:装载违例 → 613;渲染期缺必填参数 → 614(不发请求)
    public function render(array $args): string;    // 槽替换 + "[skill: name] " 前缀标记
    public function allowedToolsClause(array $registeredToolNames): ?string; // 建议段;未知名忽略+报告
}
```

注入位是刻意的设计决策:渲染产物成为**本轮的 user 消息**——它自然流经历史,持久化、压缩、rewind 零特殊处理(且与 `Memory` 的 system 注入永不冲突)。

## Usage Examples

### 会话保存 / 恢复

```php
use Ws\Http\NanoGpt\{FileConversationStore, Conversation};

$store = new FileConversationStore('.runtime/sessions');
$store->save('20261027-090000', $agent->conversation()->snapshot());

// 之后(或新进程里):
$snapshot = $store->load('20261027-090000');
$conversation = Conversation::restore($snapshot);
$agent = new Agent($transport, 'deepseek-chat', 8, $conversation);
```

### 预算驱动的自动压缩(壳行为)

```php
use Ws\Http\NanoGpt\{ContextBudget, LlmCompactor};

$budget = new ContextBudget(65536);
if ($budget->needsCompact($agent->conversation()->messages())) {
    $summary = (new LlmCompactor($transport, 'deepseek-chat'))->compact(
        array_slice($agent->conversation()->messages(), 0, -4)  // 保留最近 2 轮
    );
    $agent->conversation()->reset();
    $agent->conversation()->append($summary);
    // 用量经 restoreUsage() 保留——费用口径与窗口占用分离
}
```

### 长期记忆

```php
use Ws\Http\NanoGpt\Memory;

$memory = new Memory('.work/memory.json');
$memory->remember('用户偏好 PHP 7.4 strict types');
$memory->remember('部署固定在周四', ['ops']);

// 下一轮之前:
$base = $agent->conversation()->system();
$block = $memory->systemPromptBlock();
$agent->conversation()->setSystem(/* base + "\n\n" + $block */);
```

### 技能

```php
use Ws\Http\NanoGpt\SkillLoader;

$skills = (new SkillLoader('skills/'))->loadAll();

$definition = $skills['json-report'];
$message = $definition->render(['data.json'])                       // 位置参数:按声明序对位
        . $definition->allowedToolsClause($agent->tools()->names());

foreach ($agent->run($message) as $event) { /* 正常事件流 */ }
// "[skill: json-report] ..." user 消息像任何普通轮次一样留在历史里。
```

## Configuration

| 项 | 位置 | 默认 |
|---|---|---|
| 会话目录 | store 构造器 | `.runtime/sessions`(壳) |
| 快照版本 | `Conversation::snapshot()` | `v: 1` |
| 预算预留 | `ContextBudget::__construct` | 4096 tokens |
| 压缩阈值 | `needsCompact()` | (窗口 − 预留) 的 80% |
| 工具结果截断 | `LlmCompactor` | 2000 字符(仅摘要输入) |
| 记忆容量 | `Memory::__construct` | 100 条 |
| 技能命名 | S1 | `^[a-z][a-z0-9-]*$` |

## Dependencies

- **依赖**:`Conversation`(快照主体)、`ChatTransportInterface`(LlmCompactor 经同一传输自调 LLM)、`AgentException` 610–614 码段。
- **被依赖**:`examples/cc-gpt` 壳(`/compact` `/rewind` `/clear` `/resume` `/memory` `/skill` 命令,每轮前自动压缩)。

设计详情:[design/24-会话与记忆设计.md](../../../design/24-会话与记忆设计.md)、[design/26-Skill设计.md](../../../design/26-Skill设计.md)。
