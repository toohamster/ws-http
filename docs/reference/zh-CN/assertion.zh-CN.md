[English](../assertion.md) | [中文版本](./assertion.zh-CN.md)

# 断言层(Watcher)

> 模块:`Ws\Http\Assert` — `Watcher`、`AssertionRunner`、`Assertion`、`AssertionResult`、`Comparison`、`AssertionException`

## Overview

断言层验证 HTTP `Response`。同一套操作符底座提供**两种互补模式**:

1. **流式(`Watcher`)**——链式流畅断言;**首个失败即抛** `AssertionException`。适合快速冒烟:第一个问题就是最关心的问题;
2. **收集式(`AssertionRunner` + `Watcher::collect()`)**——全部断言执行,失败**汇入结果集**(绝不抛出)。适合测试报告:要看到全貌。

比较操作符活在**注册表**(`Comparison`)里:内建操作符预注册,业务专属操作符实现一个接口即可加入。

## Key Components

### `Watcher`(流式)

```php
public function __construct(Response $response)
public static function create(Response $response): self    // 便捷构造
public function setResponse(Response $response): self

public function assertStatusCode(int $statusCode): self
public function assertTotalTimeLessThan(float $seconds): self
public function assertHeadersExist(array $names): self            // 仅存在性
public function assertHeaders(array $headers): self               // name => 期望值(头名大小写不敏感)
public function assertBody(string $expected, bool $isRegex = false): self   // 体包含期望
public function assertBodyJson($expected, bool $showDetail = false): self   // 整体 JSON 比较
public function assertBodyJsonFile(string $file, bool $showDetail = false): self
public function assertJsonPath(string $path, string $op, $expected): self   // 如 ('$.id', 'eq', 42)

public function collect(callable $assertions): array   // 收集式:失败返回而非抛出
public function collectResults(): array                // 已收集的 AssertionResult[]
```

### `Comparison`(操作符注册表)

```php
public static function register(string $op, ComparatorInterface $impl): void
public static function compare(string $op, $actual, $expected): bool
```

内建操作符:

| 分组 | 操作符 |
|---|---|
| 相等 | `eq`、`ne`(宽松比较语义)、`not_null`、`is_null` |
| 字符串 | `contains`、`not_contains`、`starts_with`、`ends_with`、`matches`(正则) |
| 数值 | `gt`、`ge`、`lt`、`le` |
| 长度 | `length_eq`、`length_gt`、`length_lt`(字符串与数组) |
| 类型 | `type`(如 `'string'`、`'int'`、`'array'`) |
| 原始体 | `raw_empty`、`raw_valid_json`、`body_json` |

### `AssertionRunner`(收集式,场景引擎面向)

```php
public function __construct(?ExpressionEvaluator $evaluator = null)
public function run(Response $response, array $assertions): ResultSet      // 按 failFast 策略停
public function runAll(Response $response, array $assertions): array       // 全部执行
public function runOnePublic(Response $response, Assertion $assertion): AssertionResult
```

### `AssertionException`

错误码(3xx 段):`301` 状态码 / `302` 头缺失 / `303` 头值不符 / `304` 体断言失败 / `305` JSON 断言失败。

## Usage Examples

### 链式断言(首败即抛)

```php
use Ws\Http\Assert\Watcher;

$response = (new \Ws\Http\Request())->get('https://httpbin.org/get');

Watcher::create($response)
    ->assertStatusCode(200)
    ->assertBody('IS_VALID_JSON')
    ->assertHeadersExist(['content-type'])
    ->assertJsonPath('$.url', 'contains', 'httpbin.org');
```

### 收集式(报告全貌)

```php
$failures = Watcher::create($response)->collect(function (Watcher $w): void {
    $w->assertStatusCode(200);
    $w->assertJsonPath('$.headers.Accept', 'contains', 'json');
    $w->assertTotalTimeLessThan(2.0);
});

// $failures: 失败消息数组([] = 全部通过)
```

### 注册自定义操作符

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

无配置——除操作符注册表外该层无状态。断言语义细节(宽松相等归一化、正则锚定)由 `Comparison` 的内建实现自文档。

## Dependencies

- **依赖**:`Ws\Http\Response`(断言目标)、`Ws\Http\Expression\ExpressionEvaluator`(`assertJsonPath` 的 JSONPath)、`Ws\Http\Contract\ComparatorInterface` / `FnComparator`。
- **被依赖**:`Automated\AssertionRunner` 消费方(场景引擎)、对临时响应做断言的最终用户。

设计详情:[design/12-断言器设计.md](../../../design/12-断言器设计.md)。
