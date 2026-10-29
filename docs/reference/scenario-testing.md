[English](./scenario-testing.md) | [中文版本](./scenario-testing.zh-CN.md)

# Scenario Testing Engine

> Module: `Ws\Http\Automated` — `ScenarioParser`, `Scenario`, `Runner`, `Step` implementations (`HttpStep`, `DelayStep`, `PauseStep`), `StepResult`, `Report`, `Render\*`
> Companion docs: [variables-and-extraction.md](variables-and-extraction.md) · [cookies-and-auth.md](cookies-and-auth.md) · [data-driven-testing.md](data-driven-testing.md) · [scenario-outputs.md](scenario-outputs.md)

## Overview

The scenario engine executes **JSON-described API test flows** — "login → fetch token → place order" chains — without writing imperative code. A scenario file declares:

- **`variables`** — initial values (with optional `secret` flag);
- **`steps`** — HTTP requests, delays, and interactive pauses;
- **`extract`** — response → variable bindings (JSONPath and other sources);
- **`assertions`** — per-step verification using the operator registry;
- **`settings`** — failFast, cookie store, timeout, proxy, redirects, dataset iteration;
- **`outputs`** — optional declared result-set signature (scenario as a data function).

Execution is centralized in `Runner` (one class, one `run()`); results are a structured `Report` (JSON-serializable via `toArray()`), renderable as text or JSON by pluggable renderers.

## Key Components

### `ScenarioParser`

```php
public function parse(string $json): Scenario          // throws AutomatedException 401 (invalid JSON) / 402 (validation)
public function parseFile(string $path): Scenario
public function parseArray(array $data): Scenario       // test-friendly entry point
```

Validation rules **V1–V19** run at load time (strict mode: unknown fields rejected; violations carry a JSON Pointer). Highlights: unique step ids (V4), method×body-mode matrix (V5), duration formats (V6), auth/proxy shape (V7), variable naming (V8), JSONPath pre-validation (V9), dataset references (V14–V16), redirect shape (V18), `outputs` producibility (V19).

### `Runner`

```php
public function __construct(
    \Ws\Http\Contract\RequestFactoryInterface $factory,
    ?ScenarioResolver $resolver = null,
    ?VarExtractor $extractor = null,
    ?AssertionRunner $assertionRunner = null,
    ?Pause\PauseRegistry $pauseRegistry = null
)
public function run(Scenario $scenario): Report
public function runStep(Step $step, VariableScope $scope, CookieStoreInterface $cookies, Scenario $scenario): StepResult
```

Execution flow per step: resolve `${var}` placeholders → send via the injected `RequestFactory` → `collectFrom` cookie jar (any status) → run extraction → run assertions → build `StepResult`. `failFast` (default on) skips remaining steps after the first failure (marked `skipped` with reason).

Configuration precedence: engine built-ins < scenario `settings` < step-level overrides.

### `StepResult` (public readonly fields)

```php
public $stepId; public $stepName; public $stepType;   // http|delay|pause
public $status;        // passed|failed|skipped
public $failReason;    // ?string — root cause summary
public $request;       // ?array{method, url} (redacted at report output)
public $statusCode;    // ?int
public $durationMs;    // ?float — filled by Runner after execution
public $responseExcerpt; // ?string — first 2KB of raw body
public $assertionResults; // ResultSet
public $extractedVariables; // array<string, mixed> (redacted)
public $warnings;      // string[] (e.g. extract skipped)
public $meta;          // ?array — step-type specific (pause: source/var)
```

### `Report`

```php
public function steps(): array
public function passedCount(): int
public function failedCount(): int
public function skippedCount(): int
public function isSuccess(): bool            // failedCount() === 0
public function totalDurationMs(): float
public function variables(): array           // final variable snapshot (already redacted)
public function toArray(): array             // JSON report contract
```

### Renderers (`Automated\Render`)

```php
interface ReportRendererInterface { public function render($report): string; }
final class TextRenderer implements ReportRendererInterface {}
final class JsonRenderer implements ReportRendererInterface {}
```

### CLI (`bin/ws-http`)

```
php bin/ws-http run <script.json...> [options]

  --format=text|json     report format (default text)
  --report-out=<file>    write JSON report to file
  --no-fail-fast         continue after failed steps
  --var name=value       override/append scenario variable (repeatable)
  --dataset=<file>       data-driven batch mode
  --stop-on-parse-error  abort on first parse error

Exit codes: 0 = all success, 1 = failed steps, 2 = parse error
```

## Usage Examples

### A two-step scenario (login → authenticated call)

`smoke.json`:

```jsonc
{
  "id": "smoke", "name": "Smoke test",
  "variables": [ { "name": "password", "value": "secret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "name": "Login", "url": "https://api.example.com/login",
      "method": "POST", "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" },
      "extract": [ { "var": "token", "source": "json", "path": "$.token" } ] },
    { "type": "http", "id": "me", "name": "Me", "url": "https://api.example.com/me",
      "method": "GET", "headers": { "Authorization": "Bearer ${token}" },
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
```

Run it:

```shell
php bin/ws-http run smoke.json                    # text report; exit 1 on failure
php bin/ws-http run a.json b.json --format=json   # JSON report for CI
php bin/ws-http run smoke.json --var env=prod     # inject/override variables
```

### Programmatic execution

```php
use Ws\Http\Automated\{Runner, ScenarioParser};
use Ws\Http\Automated\RequestFactory;

$runner = new Runner(new RequestFactory());       // default factory = real HTTP
$report = $runner->run((new ScenarioParser())->parseFile('smoke.json'));

if (!$report->isSuccess()) {
    foreach ($report->steps() as $step) {
        if ($step->status === 'failed') {
            echo $step->stepId, ': ', $step->failReason, PHP_EOL;
        }
    }
}
```

### With a fake transport (no network in tests)

```php
$factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
$factory->queue(new \Ws\Http\Response(
    ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1],
    '{"token":"t-1"}',
    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
));

$runner = new Runner($factory);
$report = $runner->run((new ScenarioParser())->parseArray($scenarioData));
```

## Configuration

Scenario-level `settings` (step-level overrides apply):

| Key | Default | Meaning |
|---|---|---|
| `timeout` | `"30s"` | per-request timeout (`ms`/`s`/`m` suffixes) |
| `failFast` | `true` | skip remaining steps after first failure |
| `cookieStore` | `"memory"` | `memory` / `none` / `file:<path>` |
| `proxy` / `auth` | — | inherited by HTTP steps |
| `redirect` | `{follow: true, max: 10}` | `follow:false` forbids explicit `max` (V18) |
| `iterate` | — | dataset name for batch mode (see [data-driven-testing.md](data-driven-testing.md)) |

## Dependencies

- **Depends on**: core `Request` (via `RequestFactoryInterface` — injectable, mockable), `Assert\AssertionRunner`, `Expression\ExpressionEvaluator`, `Automated\Pause\PauseRegistry` (only when pause steps exist).
- **Depended on by**: `bin/ws-http` CLI, `Automated\Dataset\DatasetRunner` (batch mode), `NanoGpt\Tool\ApiFetchTool` (scenario-as-data-function mode), `Ws\Http\Automated\StaticRequestFactory` (injected-transport wiring).

Design details: [design/16-执行器设计.md](../../design/16-执行器设计.md), [design/14-场景脚本Schema%20v2.md](../../design/14-场景脚本Schema%20v2.md).
