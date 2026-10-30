[English](./variables-and-extraction.md) | [中文版本](./zh-CN/variables-and-extraction.zh-CN.md)

# Variables & Extraction

> Module: `Ws\Http\Automated` — `VariableScope`, `VarExtractor`, `VarValue`, `ExtractionResult`, `ScenarioResolver`, `Redactor`; contract: `Ws\Http\Contract\ExtractorInterface`

## Overview

Two mechanisms cooperate to move data through a scenario:

1. **`VariableScope`** — the variable container. Holds declared initial values plus everything extracted at runtime; knows which names are `secret`.
2. **`VarExtractor`** — the extraction engine. After each HTTP step it applies the step's `extract` rules to the `Response` and writes results back into the scope.

Substitution happens through **`ScenarioResolver`**: any string inside a scenario (URLs, headers, body content) may embed `${var}` placeholders, resolved deeply (nested arrays/objects included) before a step executes.

## Key Components

### `VariableScope`

```php
public function __construct(array $initialVariables)   // [{name, value, secret?}, ...]
public function has(string $name): bool
public function get(string $name): VarValue            // ->value, ->isSecret
public function set(string $name, $value, bool $isSecret = false): void
public function snapshot(): array                      // name => value (final state)
public function secrets(): array                       // secret variable names
```

Semantics: `undefined ≠ null ≠ ''` — a never-set variable is distinct from an empty one (extraction `onMissing` policies rely on this).

### `VarExtractor`

```php
public function __construct(?ExpressionEvaluator $evaluator = null)
public static function registerSource(string $name, ExtractorInterface $impl): void  // extension point
public function extract(Response $response, array $rules, VariableScope $scope): ExtractionResult
```

Extraction rule shape (per step `extract[]` entry):

| Field | Meaning |
|---|---|
| `var` | target variable name (naming rule: `[A-Za-z_][A-Za-z0-9_]*`) |
| `source` | `json` \| `header` \| `raw_body` \| `status` \| `time` \| `template` (registry-extensible) |
| `path` | JSONPath (for `json`), header name (for `header`), `{name}` template (for `template`) |
| `multiple` | `json`: all matches (list) instead of first; `header`: all values as array |
| `onMissing` | `fail` (default — step fails) \| `skip` (leave variable untouched, add warning) \| `default` (use `defaultValue`) |
| `defaultValue` | used when `onMissing=default` and nothing matched |

### `ScenarioResolver`

```php
public function resolve($value, VariableScope $scope)   // deep ${var} substitution
```

`${password}` inside a JSON body string is replaced before the request is assembled. Secrets are **not** special-cased at substitution (they must be usable); they are redacted only at the **output** boundary.

### `Redactor`

```php
Redactor::apply($output, array $secretValues)   // recursive; string occurrences replaced with *** (length preserved)
```

Applied to: `Report::variables()`, per-step `extractedVariables`, request summaries in `Report::toArray()`. Secrets can flow through the engine but never appear in any report.

## Usage Examples

### Extraction across sources

```jsonc
{
  "extract": [
    { "var": "token",   "source": "json",    "path": "$.data.token" },
    { "var": "ids",     "source": "json",    "path": "$.items[*].id", "multiple": true },
    { "var": "ctype",   "source": "header",  "path": "Content-Type" },
    { "var": "spent",   "source": "time" },
    { "var": "greeting","source": "template","path": "Hello {name}" }
  ]
}
```

### onMissing policies

```jsonc
{ "var": "limit", "source": "json", "path": "$.config.limit",
  "onMissing": "default", "defaultValue": 100 }      // absent → 100
{ "var": "etag",  "source": "header", "path": "ETag",
  "onMissing": "skip" }                              // absent → warning, variable untouched
```

### Secrets in practice

```jsonc
{
  "variables": [ { "name": "password", "value": "s3cret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "url": "https://api.example.com/login", "method": "POST",
      "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" } }
  ]
}
// Works: ${password} substitutes normally during execution.
// Report: variables() and step request summaries show *** — never the real value.
```

## Configuration

No global configuration. Behavior is controlled per extraction rule (`onMissing`, `multiple`, `defaultValue`) and per variable (`secret`). Custom sources are added via `VarExtractor::registerSource('xml', $impl)` where `$impl` satisfies:

```php
interface ExtractorInterface {
    /** @return ExtractionOutcome (matched values or a structured miss) */
    public function extract(Response $response, string $path): ExtractionOutcome;
}
```

## Dependencies

- **Depends on**: `Ws\Http\Expression\ExpressionEvaluator` (`json` source), `Ws\Http\Response`, `Ws\Http\Automated\Redactor`.
- **Depended on by**: `Automated\Runner` (per-step extraction phase), `Automated\ScenarioParser` (rule validation), `NanoGpt\Tool\ApiFetchTool` (scenario mode shares the same pipeline).

Design details: [design/15-变量系统设计.md](../../design/15-变量系统设计.md).
