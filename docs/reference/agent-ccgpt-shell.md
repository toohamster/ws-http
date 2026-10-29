[English](./agent-ccgpt-shell.md) | [中文版本](./agent-ccgpt-shell.zh-CN.md)

# cc-gpt Shell (Reference CLI)

> Module: `examples/cc-gpt` — `CcGpt\Application`, `Context`, `Settings`, `Command\*`, `Ui\*`, `ModelProvider\*`
> The first consumer of the NanoGpt component; doubles as the reference implementation for embedding it.

## Overview

`cc-gpt` is a terminal REPL that assembles the full NanoGpt stack — agent, tools, sessions, memory, skills — behind a command surface. It demonstrates every integration point a custom shell would need:

- **Assembly** (`Application::bootstrapAgent`): `settings.adapter` → vendor transport class; one shared transport instance serves the agent, compaction, and window probing;
- **Directory layout** (shell root, independent of CWD):

```
.settings.json   app config (written by /init; gitignored)
.work/           sandbox root — file-tool activity area (--work <dir> overrides)
.runtime/        drafts — agent intermediate scripts; auto-created, safe to wipe
tools/           declarative tool descriptors (*.tool.json) — loaded at boot
skills/          skill packs (*.skill.json) — user assets, shareable, NOT auto-created
```

- **Config chain**: env (`CC_GPT_API_KEY`, `CC_GPT_BASE_URL`) > `.settings.json` > prompt to run `/init`.

## Key Components

### Commands

| Command | Behavior |
| --- | --- |
| `/init [key] [url]` | Interactive: adapter menu → prompts → writes `.settings.json`. Non-interactive: `/init --adapter <id> <key> <url>` |
| `/model [id\|index]` | List catalog (`ModelInfo` table) / switch. After switching: reconcile — budget rebuild → three states (confirm / warn / over-budget menu `[c]ompact [r]ewind [k]eep`); unknown window → probe cascade (probe → accept/manual/skip) |
| `/skill [name args... \| name --info]` | Run a declared skill (rendered instruction becomes this turn's user message) / preview template |
| `/compact [keep]` | Manual compaction (keep N turns, default 2); auto-saves session |
| `/rewind` | List user checkpoints (compaction summaries marked) → truncate to chosen boundary |
| `/clear` | Delete current session file, generate new session id, reset conversation |
| `/resume [id]` | List sessions / restore a snapshot (messages, usage, system) |
| `/memory ...` | `remember <text>` / `list` / `forget <n>` — explicit long-term memory |
| `/context` | Dual accounting: cumulative usage (cost) + current window usage (budget estimate %) |
| `/help`, `/quit` | Command list / exit (Ctrl-D works) |

Non-command input goes to the agent; every turn runs **auto-compaction first** (`settings.compactAuto`, default on) and renders the event stream (text / tool calls / errors).

### Model provider adapters (`CcGpt\ModelProvider`)

Thin shells over transports — catalog resolution lives in `Transport::capabilities()`:

```php
abstract class AbstractServiceAdapter implements ModelSourceInterface
{
    final public function models(): array;         // delegates to transport->capabilities()['models'], fault-tolerant
    abstract public static function id(): string;      // self-report → settings.adapter
    abstract public static function label(): string;   // /init menu line
    abstract public static function prompts(): array;  // /init input collection
}
```

Built-ins: `OrcaRouterAdapter` (free-model filter), `GenericAdapter` (any OpenAI-compatible service). `settings.adapter` dispatches to the matching **vendor transport class**; unknown/absent → `GenericOpenAiTransport` (escape hatch).

### Settings

`.settings.json` keys: `adapter`, `apiKey`, `baseUrl`, `model`, `modelProfiles` (per-model `contextWindow` + provenance — written by the probe cascade or by hand), `contextWindow` (fallback), `compactAuto`, `httpAllowHosts` (enables network tools when non-empty), `models` (hand-written catalog → static adapter).

## Usage Examples

### First run

```shell
cd examples/cc-gpt && /usr/local/bin/php74 bin/cc-gpt

you> /init
select model provider adapter:
  1) orcarouter (free model filter)
  2) generic (any OpenAI-compatible service)
adapter [1-2]: 1
API key: sk-...
Base URL [https://api.orcarouter.ai/v1]:
adapter set to orcarouter; settings saved to .../.settings.json
```

### Daily use

```
you> /model                       # browse catalog, switch by index
you> /skill json-report data.json # run a declared skill
you> Analyze the file I just made # free-form → agent loop
⚙ read_file {"path":"..."}
  ↳ ...
you> /context                     # usage + window accounting
you> /compact 1                   # keep 1 turn, summarize the rest
```

### Embedding the shell pattern (custom assembly)

```php
use CcGpt\Application;

$app = new Application('/repo/root', $output, $input, '/custom/work/dir');
$app->bootstrapAgent($sandbox);           // re-runnable (e.g. after /init writes settings)
$app->run();                              // REPL; or drive $app->context()->agent directly
```

## Configuration

| Key | Effect |
| --- | --- |
| `compactAuto` | `false` disables pre-turn auto-compaction |
| `httpAllowHosts` | non-empty array enables `http_get`/`api_test`/`api_fetch`/`api_json_post` with this whitelist |
| `modelProfiles.<id>.contextWindow` | per-model window (drives budget + reconciliation) |

## Dependencies

- **Depends on**: the whole NanoGpt component (agent, session, memory, skills), `Ws\Http\NanoGpt\Transport\*` (vendor classes), `Ws\Http\NanoGpt\DescriptorToolLoader` (tools/), `Sandbox`.
- **Depended on by**: nothing (it is a leaf/reference example).

Runnable smoke: `examples/cc-gpt/bin/smoke` (three chains: models / chat / tools, real network) and `examples/cc-gpt/bin/smoke-d2` (transport→agent chain).

Design details: [design/21-NanoGpt设计.md](../../design/21-NanoGpt设计.md) §8.
