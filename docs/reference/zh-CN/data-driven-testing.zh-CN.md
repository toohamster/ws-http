[English](../data-driven-testing.md) | [中文版本](./data-driven-testing.zh-CN.md)

# 数据驱动测试(数据集)

> 模块:`Ws\Http\Automated\Dataset` — `DatasetRunner`、`BatchReport`、`RecordResult`、`DatasetRegistry`、`DatasetLoaderInterface`、`InlineLoader`、`JsonFileLoader`、`DatasetValidator`

## Overview

数据驱动测试把一个场景**按数据集逐条记录重跑**——场景是测试逻辑,数据集提供变化。当同一流程需要针对大量输入(用户角色、语言、载荷形态)验证时,测试逻辑只写一处。

设计决策:

- 记录是**对象**(`DatasetValidator` 强制形态一致);
- 迭代**串行**(记录级并行有意不实现);
- 每条记录产出自己的子 `Report`;批次由 `BatchReport` 汇总;
- 记录可经 `withRecordVar` 注入场景,作为 `${record}` 变量(字段经 `${record.field}` 访问)。

## Key Components

### `DatasetRunner`

```php
public function __construct(Runner $runner)
public function run(Scenario $scenario, array $records, bool $withRecordVar = false): BatchReport
```

每条记录跑一次场景;一条失败**不会**中断批次(全部记录都执行——批次语义不同于 `failFast`)。

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
public function toArray(): array          // JSON 批量报告契约
```

### `RecordResult`

包裹每条记录的 `Report` 及其数据集索引(与记录本身),失败可追溯到确切的输入行。

### 数据集声明(场景 `datasets` + `settings.iterate`)

```jsonc
{
  "id": "role-flow", "name": "角色流程",
  "datasets": {
    "users": [                                   // 内联数据集
      { "user": "admin", "expected": 200 },
      { "user": "guest", "expected": 403 }
    ],
    "big": { "file": "fixtures/users.json" }     // 文件数据集
  },
  "settings": { "iterate": "users" },            // 驱动运行的数据集
  "steps": [ ... "${user}" ... "${expected}" ... ]
}
```

规则:V14(datasets 必须是对象,值为记录数组或 `{file}`/`{loader}` 规格)、V15(`iterate` 必须引用已声明数据集)、V16(记录必须是非空对象)。

### `DatasetRegistry`(自定义加载器)

```php
public function register(string $name, DatasetLoaderInterface $loader): void
public function has(string $name): bool
public function load(string $name, array $spec): array   // 返回记录列表
```

`InlineLoader` 与 `JsonFileLoader` 为内建;CSV、数据库 fixture 等自行实现 `DatasetLoaderInterface::load(array $spec): array` 注册即可。

## Usage Examples

### CLI 内联数据集

```shell
php bin/ws-http run role-flow.json                          # 场景声明 iterate → 批量模式
php bin/ws-http run role-flow.json --dataset=extra-users.json --dataset-name=_cli
```

`--dataset` 把驱动数据集切到一个 JSON 记录文件,并注册为 `_cli`(除非 `--dataset-name` 指定)。

### 编程式批量

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
        true   // 每条记录注入为 ${record}
    );

echo $batch->passed(), '/', $batch->total(), ' passed', PHP_EOL;
foreach ($batch->failedRecordIndexes() as $i) {
    echo 'record ', $i, ' failed', PHP_EOL;
}
echo json_encode($batch->toArray(), JSON_PRETTY_PRINT);  // CI 可消费的批量报告
```

## Configuration

- **数据集选择**:场景 `settings.iterate`,或 CLI `--dataset`(覆盖);
- **记录注入**:`withRecordVar` / CLI 默认行为——记录暴露为 `${record}`(字段:`${record.user}`);
- **形态一致**:`DatasetValidator` 拒绝非对象记录(420–422 错误段)。

## Dependencies

- **依赖**:`Automated\Runner`(每条记录一次 run)、`ScenarioParser`(数据集声明校验)。
- **被依赖**:`bin/ws-http` CLI 批量模式。

设计详情:[design/23-数据驱动测试设计.md](../../../design/23-数据驱动测试设计.md)。
