[English](./assertion.md) | [中文版本](./zh-CN/assertion.zh-CN.md)

# Assertion Layer (Watcher)

> Module: `Ws\Http\Assert` — `Watcher`, `AssertionRunner`, `Assertion`, `AssertionResult`, `Comparison`, `AssertionException`

## Overview

The assertion layer verifies an HTTP `Response`. It offers **two complementary modes** with the same underlying operators:

1. **Streaming (`Watcher`)** — fluent chained assertions; the **first failure throws** `AssertionException`. Ideal for quick smoke checks where the first problem is the interesting one.
2. **Collect mode (`AssertionRunner` + `Watcher::collect()`)** — all assertions run; failures are **gathered** into a result set (never thrown). Ideal for test reporting where you want the full picture.

Comparison operators live in a **registry** (`Comparison`): built-ins are pre-registered, and business-specific operators can be added with a one-interface implementation.

## Key Components

### `Watcher` (fluent)

```php
public function __construct(Response $response)
public static function create(Response $response): self    // convenience constructor
public function setResponse(Response $response): self

public function assertStatusCode(int $statusCode): self
public function assertTotalTimeLessThan(float $seconds): self
public function assertHeadersExist(array $names): self            // presence only
public function assertHeaders(array $headers): self               // name => expected value (case-insensitive names)
public function assertBody(string $expected, bool $isRegex = false): self   // body contains expected
public function assertBodyJson($expected, bool $showDetail = false): self   // full-body JSON comparison
public function assertBodyJsonFile(string $file, bool $showDetail = false): self
public function assertJsonPath(string $path, string $op, $expected): self   // e.g. ('$.id', 'eq', 42)

public function collect(callable $assertions): array   // collect mode: failures returned, not thrown
public function collectResults(): array                // AssertionResult[] gathered so far
```

### `Comparison` (operator registry)

```php
public static function register(string $op, ComparatorInterface $impl): void
public static function compare(string $op, $actual, $expected): bool
```

Built-in operators:

| Group | Operators |
|---|---|
| Equality | `eq`, `ne` (loose comparison semantics), `not_null`, `is_null` |
| Strings | `contains`, `not_contains`, `starts_with`, `ends_with`, `matches` (regex) |
| Numeric | `gt`, `ge`, `lt`, `le` |
| Length | `length_eq`, `length_gt`, `length_lt` (strings and arrays) |
| Type | `type` (e.g. `'string'`, `'int'`, `'array'`) |
| Raw body | `raw_empty`, `raw_valid_json`, `body_json` |

### `AssertionRunner` (collect mode, scenario-facing)

```php
public function __construct(?ExpressionEvaluator $evaluator = null)
public function run(Response $response, array $assertions): ResultSet      // stops per failFast policy
public function runAll(Response $response, array $assertions): array       // runs everything
public function runOnePublic(Response $response, Assertion $assertion): AssertionResult
```

### `AssertionException`

Error codes (3xx segment): `301` status / `302` header missing / `303` header value / `304` body / `305` JSON.

## Usage Examples

### Chained assertions (first failure throws)

```php
use Ws\Http\Assert\Watcher;

$response = (new \Ws\Http\Request())->get('https://httpbin.org/get');

Watcher::create($response)
    ->assertStatusCode(200)
    ->assertBody('IS_VALID_JSON')
    ->assertHeadersExist(['content-type'])
    ->assertJsonPath('$.url', 'contains', 'httpbin.org');
```

### Collect mode (report everything)

```php
$failures = Watcher::create($response)->collect(function (Watcher $w): void {
    $w->assertStatusCode(200);
    $w->assertJsonPath('$.headers.Accept', 'contains', 'json');
    $w->assertTotalTimeLessThan(2.0);
});

// $failures: array of failure messages ([] = all passed)
```

### Custom operator registration

```php
use Ws\Http\Assert\Comparison;
use Ws\Http\Contract\ComparatorInterface;

Comparison::register('is_even', new class implements ComparatorInterface {
    public function compare($actual, $expected): bool
    {
        return is_numeric($actual) && ((int) $actual) % 2 === 0;
    }
});

Watcher::create($response)->assertJsonPath('$.count', 'is_even', null);
```

## Configuration

None — the layer is stateless apart from the operator registry. Assertion semantics details (loose equality normalization, regex anchoring) live in `Comparison` and are documented by their built-in implementations.

## Dependencies

- **Depends on**: `Ws\Http\Response` (the assertion target), `Ws\Http\Expression\ExpressionEvaluator` (JSONPath in `assertJsonPath`), `Ws\Http\Contract\ComparatorInterface` / `FnComparator`.
- **Depended on by**: `Automated\AssertionRunner` consumers (scenario engine), end users asserting ad-hoc responses.

Design details: [design/12-断言器设计.md](../../design/12-断言器设计.md).
