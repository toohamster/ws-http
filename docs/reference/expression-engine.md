[English](./expression-engine.md) | [中文版本](./expression-engine.zh-CN.md)

# Expression Engine (JSONPath Subset)

> Module: `Ws\Http\Expression` — `ExpressionEvaluator`, `EvaluationResult`, `Lexer`, `Parser`, `Token`, `ExpressionException`

## Overview

A compact JSONPath-subset engine used by two consumers: **variable extraction** (`Automated\VarExtractor`) and **assertions** (`Assert\Watcher::assertJsonPath`). It intentionally implements a *subset* of JSONPath with **lenient evaluation semantics**:

- **Structural mismatch returns an empty result set — it never throws.** A path that doesn't match the document shape yields an empty set, so extraction and assertions can decide their own failure policies.
- The only throwing call is `validate()`, used at load time (scenario parsing) to reject syntactically invalid paths early.

Supported syntax (subset):

- Root `$.a.b.c` — property navigation;
- `$['key']` / `$["key"]` — bracket access (keys with special characters);
- `$.list[0]`, `$.list[-1]` — array index (negative = from end);
- `$.list[*]`, `$.list[*].id` — wildcard over arrays;
- `$.items[?(@.price > 10)]` — basic filter expressions (`>`, `<`, `>=`, `<=`, `==`, `!=`, string literals in quotes);
- UTF-8 property names (`$.名`).

## Key Components

### `ExpressionEvaluator`

```php
public function __construct()
public function evaluate($data, string $expression): EvaluationResult
public function validate(string $expression): void   // throws ExpressionException (201) on syntax error
```

`$data` accepts decoded JSON (array or object tree — both supported).

### `EvaluationResult`

```php
public function isEmpty(): bool
public function count(): int
public function first()                     // mixed — first matched value, or null when empty
public function all(): array                // list of matched values
public function paths(): array              // matching JSON pointers (diagnostics)
```

### `ExpressionException`

Error codes (2xx segment):

| Code | Meaning |
|---|---|
| 201 | Syntax error (message includes the offending position) |
| 202 | Caller requested "empty set is an error" (opt-in strictness) |
| 203 | Type mismatch |

## Usage Examples

### Basic navigation

```php
use Ws\Http\Expression\ExpressionEvaluator;

$evaluator = new ExpressionEvaluator();
$data = json_decode('{"store":{"books":[{"title":"A","price":30},{"title":"B","price":5}]}}');

$result = $evaluator->evaluate($data, '$.store.books[*].title');
$result->all();          // ['A', 'B']
$result->first();        // 'A'
$result->isEmpty();      // false

$evaluator->evaluate($data, '$.store.books[?(@.price > 10)].title')->first();  // 'A'
$evaluator->evaluate($data, '$.store.missing')->isEmpty();                      // true — lenient, no throw
```

### Load-time validation (scenario authoring)

```php
try {
    $evaluator->validate('$.books[title');   // malformed
} catch (Ws\Http\Expression\ExpressionException $e) {
    echo $e->getCode();    // 201
    echo $e->getMessage(); // syntax error + position
}
```

## Configuration

The engine is configuration-free. Leniency is a semantic property of `evaluate()`; strictness belongs to callers:

- **Extraction** (`VarExtractor`): empty set + `onMissing` rule (`fail` / `skip` / `default`) decides the outcome;
- **Assertions**: an empty set makes `assertJsonPath` fail with "(no match)" in the message.

## Dependencies

- **Depends on**: nothing (self-contained lexer/parser/evaluator).
- **Depended on by**: `Automated\VarExtractor` (extraction rules), `Assert` layer (`assertJsonPath`), `Automated\ScenarioParser` (path validation during scenario parse).

Design details: [design/13-表达式引擎设计.md](../../design/13-表达式引擎设计.md) (EBNF grammar, error codes).
