[English](./data-driven-testing.md) | [中文版本](./zh-CN/data-driven-testing.zh-CN.md)

# Data-Driven Testing (Datasets)

> Module: `Ws\Http\Automated\Dataset` — `DatasetRunner`, `BatchReport`, `RecordResult`, `DatasetRegistry`, `DatasetLoaderInterface`, `InlineLoader`, `JsonFileLoader`, `DatasetValidator`

## Overview

Data-driven testing re-runs one scenario **once per record** of a dataset — the scenario is the test logic, the dataset supplies the variation. This keeps test logic in one place when the same flow must be verified against many inputs (user roles, locales, payload shapes).

Design decisions:

- Records are **objects** (uniform shape enforced by `DatasetValidator`);
- Iteration is **serial** (record-level parallelism deliberately not implemented);
- Each record produces its own sub-`Report`; the batch is summarized by `BatchReport`;
- A record is optionally injected into the scenario as the `${record}` variable (`withRecordVar`), exposing fields via `${record.field}`.

## Key Components

### `DatasetRunner`

```php
public function __construct(Runner $runner)
public function run(Scenario $scenario, array $records, bool $withRecordVar = false): BatchReport
```

Runs the scenario once per record; a failed record does **not** stop the batch (all records execute — batch semantics differ from `failFast`).

### `BatchReport`

```php
public function add(RecordResult $record): void
public function finalize(float $totalDurationMs): void
public function records(): array          // RecordResult[]
public function total(): int
public function passed(): int
public function failed(): int
public function isSuccess(): bool
public function failedRecordIndexes(): array
public function totalDurationMs(): float
public function toArray(): array          // JSON batch report contract
```

### `RecordResult`

Wraps the per-record `Report` plus its dataset index (and the record itself) so failures trace back to the exact input row.

### Dataset declaration (scenario `datasets` + `settings.iterate`)

```jsonc
{
  "id": "role-flow", "name": "Role flow",
  "datasets": {
    "users": [                                   // inline dataset
      { "user": "admin", "expected": 200 },
      { "user": "guest", "expected": 403 }
    ],
    "big": { "file": "fixtures/users.json" }     // file-backed dataset
  },
  "settings": { "iterate": "users" },            // which dataset drives the run
  "steps": [ ... "${user}" ... "${expected}" ... ]
}
```

Rules: V14 (datasets must be an object of record arrays or `{file}`/`{loader}` specs), V15 (`iterate` must reference a declared dataset), V16 (records must be non-empty objects).

### `DatasetRegistry` (custom loaders)

```php
public function register(string $name, DatasetLoaderInterface $loader): void
public function has(string $name): bool
public function load(string $name, array $spec): array   // returns record list
```

`InlineLoader` and `JsonFileLoader` are built-ins; register your own (`DatasetLoaderInterface::load(array $spec): array`) for CSV, database fixtures, etc.

## Usage Examples

### Inline dataset via CLI

```shell
php bin/ws-http run role-flow.json                          # scenario declares iterate → batch mode
php bin/ws-http run role-flow.json --dataset=extra-users.json --dataset-name=_cli
```

`--dataset` switches the driven dataset to a JSON file of records and registers it as `_cli` (unless `--dataset-name` given).

### Programmatic batch

```php
use Ws\Http\Automated\{Runner, ScenarioParser, RequestFactory};
use Ws\Http\Automated\Dataset\DatasetRunner;

$batch = (new DatasetRunner(new Runner(new RequestFactory())))
    ->run(
        (new ScenarioParser())->parseFile('role-flow.json'),
        [
            ['user' => 'admin', 'expected' => 200],
            ['user' => 'guest', 'expected' => 403],
        ],
        true   // inject each record as ${record}
    );

echo $batch->passed(), '/', $batch->total(), ' passed', PHP_EOL;
foreach ($batch->failedRecordIndexes() as $i) {
    echo 'record ', $i, ' failed', PHP_EOL;
}
echo json_encode($batch->toArray(), JSON_PRETTY_PRINT);  // CI-consumable batch report
```

## Configuration

- **Dataset selection**: `settings.iterate` (scenario) or `--dataset` (CLI, overrides).
- **Record injection**: `withRecordVar` / CLI default behavior — record exposed as `${record}` (fields: `${record.user}`).
- **Uniformity**: `DatasetValidator` rejects non-object records (420–422 error segment).

## Dependencies

- **Depends on**: `Automated\Runner` (one run per record), `ScenarioParser` (dataset declaration validation).
- **Depended on by**: `bin/ws-http` CLI batch mode.

Design details: [design/23-数据驱动测试设计.md](../../design/23-数据驱动测试设计.md).
