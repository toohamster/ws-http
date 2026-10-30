[English](./error-codes.md) | [中文版本](./zh-CN/error-codes.zh-CN.md)

# Error Code Reference

> Cross-module quick lookup. Every `Exception` in ws-http carries a segment code identifying its layer and failure class.
> Segments: `1xx` transport · `2xx` expression · `3xx` assertion · `4xx` scenario engine · `5xx` plugin · `6xx` NanoGpt

## Code Map

### 1xx — Transport (`RequestException`)

| Code | Meaning | Typical source |
|---|---|---|
| curl errno | Transport failure; the code **is the cURL errno** (e.g. 6 = couldn't resolve host, 7 = couldn't connect, 28 = timeout) | `Request::send()` when `curl_errno() !== 0` |

### 2xx — Expression (`ExpressionException`)

| Code | Meaning |
|---|---|
| 201 | Syntax error (message includes the offending position) |
| 202 | Empty set treated as error (caller opt-in strictness) |
| 203 | Type mismatch |

### 3xx — Assertion (`AssertionException`)

| Code | Meaning |
|---|---|
| 301 | Status code mismatch |
| 302 | Header missing |
| 303 | Header value mismatch |
| 304 | Body assertion failed |
| 305 | JSON assertion failed |

### 4xx — Scenario Engine (`AutomatedException`)

| Code | Meaning | Raised by |
|---|---|---|
| 401 | Invalid scenario JSON / unreadable file | `ScenarioParser::parse/parseFile`; cookie store write failure |
| 402 | Field validation failure (V1–V19; message carries a JSON Pointer) | `ScenarioParser` |
| 403 | Invalid step type (or filesystem write refusal for cookie store) | parser / `FileCookieStore::persist` |
| 404 | Invalid data mode | parser |
| 405 | Extraction failure (`onMissing=fail`) | `VarExtractor` |
| 406–408 | Pause value failures (source-specific: stdin/env/poll) | `Pause\*` |
| 420–422 | Dataset failures (load/shape/uniformity) | `Dataset\*` |

### 5xx — Plugin

| Code | Meaning |
|---|---|
| 501 | Plugin registration conflict (duplicate name) |
| 502 | Auth configuration error (plugins may define custom sub-codes) |

### 6xx — NanoGpt (`AgentException`)

| Code | Meaning | Raised by |
|---|---|---|
| 601 | Max turns exhausted without producing text | `Agent::run` |
| 602 | Unknown tool name | `ToolRegistry::get` |
| 603 | Duplicate tool registration | `ToolRegistry::register` |
| 604 | Unexpected chat response structure | legacy structural guard (parsing now consolidated in transports → 624) |
| 605 | Truncation point is not a user boundary | `Conversation::truncateFrom` |
| 610 | Sandbox path escape (realpath containment violated) | `Sandbox::resolve` |
| 611 | Unknown session snapshot version | `Conversation::restore` (guide: start a new session) |
| 612 | Compaction failed (empty segment / LLM failure / empty summary; original messages untouched) | `LlmCompactor` |
| 613 | Descriptor/skill file invalid (name conflict, missing fields, schema violation; message includes file name) | `DescriptorToolLoader`, `SkillLoader`, `SkillDefinition` |
| 614 | Argument-slot validation failure (missing required arg) — surfaced as a tool/command return, not a throw | `SkillDefinition::render`, `CommandToolBase` |
| 620 | Transport auth failure (HTTP 401/403) | `OpenAiCompatibleTransport` |
| 621 | Transport rate limited (HTTP 429, retries exhausted) | `OpenAiCompatibleTransport` |
| 622 | Transport server error (HTTP 5xx, retries exhausted) | `OpenAiCompatibleTransport` |
| 623 | Transport timeout (connection/execution) | `OpenAiCompatibleTransport` |
| 624 | Transport unexpected response (non-retryable status or unparseable 2xx) | `OpenAiCompatibleTransport` |
| 630 | Scenario declares no outputs (data-function misuse) | `ScenarioOutput::extract` |

## Usage Examples

### Branching on a classification code (orchestration layer)

```php
use Ws\Http\NanoGpt\AgentException;

try {
    foreach ($agent->run($input) as $event) { /* render */ }
} catch (AgentException $e) {
    switch ($e->getCode()) {
        case 621: hintRateLimit(); break;   // suggest waiting or compaction
        case 620: promptRelogin(); break;
        default:  echo $e->getMessage(); break;
    }
}
```

### Parse-time vs run-time failures

```php
use Ws\Http\Automated\{ScenarioParser, Runner};
use Ws\Http\Automated\AutomatedException;

try {
    $scenario = (new ScenarioParser())->parseFile('flow.json'); // 401/402 here = authoring bug
} catch (AutomatedException $e) {
    exit(2); // CLI convention: parse error
}

$report = (new Runner($factory))->run($scenario);              // 405+ here = runtime data problem
```

## Notes

- Codes are **stable identifiers**, not display text — branch on them, match on them, log them.
- 614 (and 602 in tool context) are *registered* as tool-return codes rather than thrown exceptions: tool execution failures flow back to the model as strings by design.
- New codes are added in design docs first ([design/10 §5](../../design/10-总体架构设计.md)) then implemented — this file tracks the implemented set.
