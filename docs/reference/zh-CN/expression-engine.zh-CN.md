[English](../expression-engine.md) | [中文版本](./expression-engine.zh-CN.md)

# 表达式引擎(JSONPath 子集)

> 模块:`Ws\Http\Expression` — `ExpressionEvaluator`、`EvaluationResult`、`Lexer`、`Parser`、`Token`、`ExpressionException`

## Overview

一个精简的 JSONPath 子集引擎,服务两个消费方:**变量提取**(`Automated\VarExtractor`)与**断言**(`Assert\Watcher::assertJsonPath`)。它有意实现 JSONPath 的*子集*,并采用**宽容求值语义**:

- **结构不匹配返回空结果集——绝不抛出。** 路径与文档形状不符时得到空集,由提取与断言各自决定失败策略;
- 唯一会抛的调用是 `validate()`,用于加载期(场景解析)提前拒绝语法非法的路径。

支持的语法(子集):

- 根 `$.a.b.c` — 属性导航;
- `$['key']` / `$["key"]` — 括号访问(含特殊字符的键);
- `$.list[0]`、`$.list[-1]` — 数组索引(负数 = 从尾部);
- `$.list[*]`、`$.list[*].id` — 数组通配;
- `$.items[?(@.price > 10)]` — 基础过滤表达式(`>`、`<`、`>=`、`<=`、`==`、`!=`,引号字符串字面量);
- UTF-8 属性名(`$.名`)。

## Key Components

### `ExpressionEvaluator`

```php
public function __construct()
public function evaluate($data, string $expression): EvaluationResult
public function validate(string $expression): void   // 语法错误抛 ExpressionException(201)
```

`$data` 接受已解码 JSON(数组或对象树皆可)。

### `EvaluationResult`

```php
public function isEmpty(): bool
public function count(): int
public function first()                     // mixed — 首个匹配值;空集时 null
public function all(): array                // 匹配值列表
public function paths(): array              // 匹配的 JSON pointer(诊断用)
```

### `ExpressionException`

错误码(2xx 段):

| 码 | 含义 |
|---|---|
| 201 | 语法错误(消息含出错位置) |
| 202 | 调用方要求"空集即错"(显式选择严格模式) |
| 203 | 类型不匹配 |

## Usage Examples

### 基础导航

```php
use Ws\Http\Expression\ExpressionEvaluator;

$evaluator = new ExpressionEvaluator();
$data = json_decode('{"store":{"books":[{"title":"A","price":30},{"title":"B","price":5}]}}');

$result = $evaluator->evaluate($data, '$.store.books[*].title');
$result->all();          // ['A', 'B']
$result->first();        // 'A'
$result->isEmpty();      // false

$evaluator->evaluate($data, '$.store.books[?(@.price > 10)].title')->first();  // 'A'
$evaluator->evaluate($data, '$.store.missing')->isEmpty();                      // true — 宽容,不抛
```

### 加载期校验(场景编写)

```php
try {
    $evaluator->validate('$.books[title');   // 非法
} catch (Ws\Http\Expression\ExpressionException $e) {
    echo $e->getCode();    // 201
    echo $e->getMessage(); // 语法错误 + 位置
}
```

## Configuration

引擎免配置。宽容是 `evaluate()` 的语义属性;严格属于调用方:

- **提取**(`VarExtractor`):空集 + `onMissing` 规则(`fail` / `skip` / `default`)决定结果;
- **断言**:空集使 `assertJsonPath` 失败,消息含 "(no match)"。

## Dependencies

- **依赖**:无(自包含的 lexer/parser/evaluator)。
- **被依赖**:`Automated\VarExtractor`(提取规则)、`Assert` 层(`assertJsonPath`)、`Automated\ScenarioParser`(场景解析期路径校验)。

设计详情:[design/13-表达式引擎设计.md](../../../design/13-表达式引擎设计.md)(EBNF 文法、错误码)。
