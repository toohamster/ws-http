[English](../variables-and-extraction.md) | [中文版本](./variables-and-extraction.zh-CN.md)

# 变量与提取

> 模块:`Ws\Http\Automated` — `VariableScope`、`VarExtractor`、`VarValue`、`ExtractionResult`、`ScenarioResolver`、`Redactor`;契约:`Ws\Http\Contract\ExtractorInterface`

## Overview

两套机制协作,让数据在场景中流动:

1. **`VariableScope`** — 变量容器。持有声明的初始值与运行期提取的一切;知道哪些名字是 `secret`;
2. **`VarExtractor`** — 提取引擎。每个 HTTP 步骤之后,把该步骤的 `extract` 规则应用到 `Response`,结果写回作用域。

替换由 **`ScenarioResolver`** 完成:场景内任何字符串(URL、请求头、体内容)都可嵌入 `${var}` 占位符,步骤执行前做**深替换**(嵌套数组/对象一并处理)。

## Key Components

### `VariableScope`

```php
public function __construct(array $initialVariables)   // [{name, value, secret?}, ...]
public function has(string $name): bool
public function get(string $name): VarValue            // ->value, ->isSecret
public function set(string $name, $value, bool $isSecret = false): void
public function snapshot(): array                      // name => value(终态)
public function secrets(): array                       // 敏感变量名列表
```

语义:`undefined ≠ null ≠ ''`——从未设置的变量与空值变量是两回事(提取的 `onMissing` 策略依赖这一区分)。

### `VarExtractor`

```php
public function __construct(?ExpressionEvaluator $evaluator = null)
public static function registerSource(string $name, ExtractorInterface $impl): void  // 扩展点
public function extract(Response $response, array $rules, VariableScope $scope): ExtractionResult
```

提取规则形态(每步 `extract[]` 条目):

| 字段 | 含义 |
|---|---|
| `var` | 目标变量名(命名规则:`[A-Za-z_][A-Za-z0-9_]*`) |
| `source` | `json` \| `header` \| `raw_body` \| `status` \| `time` \| `template`(注册表可扩展) |
| `path` | JSONPath(`json`)、头名(`header`)、`{name}` 模板(`template`) |
| `multiple` | `json`:取全部匹配(列表)而非首个;`header`:全部值成数组 |
| `onMissing` | `fail`(默认——步骤失败)\| `skip`(变量不动,加警告)\| `default`(用 `defaultValue`) |
| `defaultValue` | `onMissing=default` 且无匹配时使用 |

### `ScenarioResolver`

```php
public function resolve($value, VariableScope $scope)   // 深 ${var} 替换
```

JSON 体字符串里的 `${password}` 在请求组装前被替换。敏感值在替换时**不做**特殊处理(必须可用);只在**输出**边界脱敏。

### `Redactor`

```php
Redactor::apply($output, array $secretValues)   // 递归;字符串出现处替换为 ***(保持长度)
```

应用于:`Report::variables()`、各步 `extractedVariables`、`Report::toArray()` 里的请求摘要。敏感值可以流经引擎,但绝不会出现在任何报告里。

## Usage Examples

### 跨 source 提取

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

### onMissing 策略

```jsonc
{ "var": "limit", "source": "json", "path": "$.config.limit",
  "onMissing": "default", "defaultValue": 100 }      // 缺失 → 100
{ "var": "etag",  "source": "header", "path": "ETag",
  "onMissing": "skip" }                              // 缺失 → 警告,变量不动
```

### 敏感值实战

```jsonc
{
  "variables": [ { "name": "password", "value": "s3cret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "url": "https://api.example.com/login", "method": "POST",
      "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" } }
  ]
}
// 可用:${password} 在执行期正常替换。
// 报告:variables() 与步骤请求摘要显示 ***——绝不出现真实值。
```

## Configuration

无全局配置。行为由每条提取规则(`onMissing`、`multiple`、`defaultValue`)与每个变量(`secret`)控制。自定义 source 经 `VarExtractor::registerSource('xml', $impl)` 注册,`$impl` 满足:

```php
interface ExtractorInterface {
    /** @return ExtractionOutcome(匹配值或结构化未命中) */
    public function extract(Response $response, string $path): ExtractionOutcome;
}
```

## Dependencies

- **依赖**:`Ws\Http\Expression\ExpressionEvaluator`(`json` source)、`Ws\Http\Response`、`Ws\Http\Automated\Redactor`。
- **被依赖**:`Automated\Runner`(逐步提取阶段)、`Automated\ScenarioParser`(规则校验)、`NanoGpt\Tool\ApiFetchTool`(场景形态共用同一管线)。

设计详情:[design/15-变量系统设计.md](../../../design/15-变量系统设计.md)。
