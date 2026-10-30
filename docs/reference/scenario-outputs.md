[English](./scenario-outputs.md) | [中文版本](./zh-CN/scenario-outputs.zh-CN.md)

# Scenario as Data Function (Scenario Outputs)

> Module: `Ws\Http\Automated\ScenarioOutput`; schema extension in `Ws\Http\Automated\ScenarioParser` (rule V19); consumer: `NanoGpt\Tool\ApiFetchTool`

## Overview

Beyond verification, scenarios can serve as **data functions**: define variables → run a (possibly multi-step) flow → return a **pre-declared set of output variables** as a JSON result set. The process may be arbitrarily complex; the *signature is just `outputs`*.

```jsonc
{
  "id": "get-weather", "name": "Get weather",
  "outputs": ["city", "temp"],                     // ← the only new field: result-set signature
  "variables": [ { "name": "cityCode", "value": "440100" } ],
  "steps": [
    { "type": "http", "id": "s1", "url": "https://api.example.com/weather?code=${cityCode}",
      "method": "GET",
      "extract": [ { "var": "city", "source": "json", "path": "$.city" },
                   { "var": "temp", "source": "json", "path": "$.data.temp" } ] }
  ]
}
```

Semantics:

- **Key order = declaration order** (stable, consumer/test-reliable);
- **Missing keys are omitted** (an `onMissing=fail` variable never reaches the result set — result-set completeness presumes a successful run);
- **Secrets stay masked** — the result set inherits the already-redacted `Report::variables()` snapshot; the data-function exit is not a redaction bypass;
- The engine does **not** check `Report::isSuccess()` — that is the caller's policy decision (retry / error / partial read).

## Key Components

### `ScenarioOutput`

```php
final class ScenarioOutput
{
    /**
     * @param string[] $outputs declared outputs (empty array → throw)
     * @return array<string, mixed> key order = declaration order; undeclared/absent keys omitted
     * @throws AutomatedException 630 when outputs is empty (caller misuse, caught at programming time)
     */
    public function extract(Report $report, array $outputs): array;
}
```

### Schema rule V19

`outputs` must be a non-empty string array, elements unique and name-valid; every name must be **producible** — declared in `variables` or produced by some step's `extract[].var` / pause `var`. This prevents silent typos returning empty sets. Violations fail at parse time (402, JSON Pointer included). `outputs` composes freely with `settings.iterate` (each batch record yields its own result set).

### Programmatic use

```php
$result = (new ScenarioOutput())->extract($report, $scenario->outputs);
// → ["city" => "Guangzhou", "temp" => "26C"]
```

## Usage Examples

### Two-line data function

```php
use Ws\Http\Automated\{Runner, ScenarioParser, ScenarioOutput, RequestFactory};

$scenario = (new ScenarioParser())->parseFile('get-weather.json');
$report   = (new Runner(new RequestFactory()))->run($scenario);

if ($report->isSuccess()) {
    $weather = (new ScenarioOutput())->extract($report, $scenario->outputs);
    echo $weather['city'], ' is ', $weather['temp'], PHP_EOL;
}
```

### Via the agent tool (`api_fetch` scenario mode)

The NanoGpt `api_fetch` tool accepts scenario files as its mode B:

```json
{ "scenario": "/abs/path/get-weather.json", "vars": {"cityCode": "440300"}, "outputs": ["city"] }
```

- `vars` — overrides for **declared** variables only (undeclared names ignored; signature can't be hijacked);
- `outputs` — **narrowing subset** of the declared outputs (never an extension; undeclared names are rejected);
- Response on success: `status: ok` + JSON result set; on failure: `status: failed` + `failedSteps` (id/name/reason) for diagnosis;
- Security: every HTTP URL inside the scenario is checked against the tool's host whitelist **before** execution (rejection happens without sending anything).

Single-request mode (`{url, extract[]}`) remains supported as the degenerate form.

## Configuration

None beyond the schema. The `outputs` declaration is the entire contract — "process complexity has no ceiling; the signature is only `outputs`".

## Dependencies

- **Depends on**: `Automated\Report` (redacted final variables), `Automated\ScenarioParser` (V19 validation).
- **Depended on by**: `NanoGpt\Tool\ApiFetchTool` (scenario mode), programmatic consumers.

Design details: [design/27-场景输出契约设计.md](../../design/27-场景输出契约设计.md).
