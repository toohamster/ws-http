[English](../scenario-testing.md) | [中文版本](./scenario-testing.zh-CN.md)

# 场景测试引擎

> 模块:`Ws\Http\Automated` — `ScenarioParser`、`Scenario`、`Runner`、`Step` 实现(`HttpStep`、`DelayStep`、`PauseStep`)、`StepResult`、`Report`、`Render\*`
> 配套文档:[variables-and-extraction.zh-CN.md](./variables-and-extraction.zh-CN.md) · [cookies-and-auth.zh-CN.md](./cookies-and-auth.zh-CN.md) · [data-driven-testing.zh-CN.md](./data-driven-testing.zh-CN.md) · [scenario-outputs.zh-CN.md](./scenario-outputs.zh-CN.md)

## Overview

场景引擎执行**以 JSON 描述的 API 测试流程**——"登录 → 取 token → 下单"这类链式调用——无需编写命令式代码。场景文件声明:

- **`variables`** — 初始值(可带 `secret` 标志);
- **`steps`** — HTTP 请求、延时、交互式暂停;
- **`extract`** — 响应 → 变量绑定(JSONPath 及其他 source);
- **`assertions`** — 逐步验证,使用操作符注册表;
- **`settings`** — failFast、Cookie 存储、超时、代理、重定向、数据集迭代;
- **`outputs`** — 可选的结果集签名(场景作为取数函数)。

执行集中在 `Runner`(一个类、一个 `run()`);结果是一个结构化 `Report`(经 `toArray()` 可 JSON 序列化),由可插拔渲染器输出 text 或 JSON。

## Key Components

### `ScenarioParser`

```php
public function parse(string $json): Scenario          // 非法 JSON 抛 401 / 校验失败抛 402
public function parseFile(string $path): Scenario
public function parseArray(array $data): Scenario       // 测试友好入口
```

校验规则 **V1–V19** 在加载期一次跑完(严格模式:未知字段拒绝;违例携带 JSON Pointer)。要点:步骤 id 唯一(V4)、方法×体模式矩阵(V5)、时长格式(V6)、auth/proxy 形态(V7)、变量命名(V8)、JSONPath 预校验(V9)、数据集引用(V14–V16)、重定向形态(V18)、`outputs` 可产出性(V19)。

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

每步执行流:解析 `${var}` 占位符 → 经注入的 `RequestFactory` 发送 → Cookie 罐 `collectFrom`(任何状态码)→ 提取 → 断言 → 组装 `StepResult`。`failFast`(默认开)在首个失败后跳过其余步骤(标记 `skipped` 并注明原因)。

配置优先级:引擎内建 < 场景 `settings` < 步骤级覆盖。

### `StepResult`(公开只读字段)

```php
public $stepId; public $stepName; public $stepType;   // http|delay|pause
public $status;        // passed|failed|skipped
public $failReason;    // ?string — 根因摘要
public $request;       // ?array{method, url}(报告输出时已脱敏)
public $statusCode;    // ?int
public $durationMs;    // ?float — Runner 执行后回填
public $responseExcerpt; // ?string — 原始体前 2KB
public $assertionResults; // ResultSet
public $extractedVariables; // array<string, mixed>(已脱敏)
public $warnings;      // string[](如 extract 被跳过)
public $meta;          // ?array — 步骤类型专属(pause: source/var)
```

### `Report`

```php
public function steps(): array
public function passedCount(): int
public function failedCount(): int
public function skippedCount(): int
public function isSuccess(): bool            // failedCount() === 0
public function totalDurationMs(): float
public function variables(): array           // 终态变量快照(已脱敏)
public function toArray(): array             // JSON 报告契约
```

### 渲染器(`Automated\Render`)

```php
interface ReportRendererInterface { public function render($report): string; }
final class TextRenderer implements ReportRendererInterface {}
final class JsonRenderer implements ReportRendererInterface {}
```

### CLI(`bin/ws-http`)

```
php bin/ws-http run <script.json...> [options]

  --format=text|json     报告格式(默认 text)
  --report-out=<file>    JSON 报告落盘
  --no-fail-fast         失败后继续
  --var name=value       覆盖/追加场景变量(可重复)
  --dataset=<file>       数据驱动批量模式
  --stop-on-parse-error  首个解析错误即中止

退出码:0 = 全部成功,1 = 有失败步骤,2 = 解析错误
```

## Usage Examples

### 两步场景(登录 → 鉴权调用)

`smoke.json`:

```jsonc
{
  "id": "smoke", "name": "冒烟测试",
  "variables": [ { "name": "password", "value": "secret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "name": "登录", "url": "https://api.example.com/login",
      "method": "POST", "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" },
      "extract": [ { "var": "token", "source": "json", "path": "$.token" } ] },
    { "type": "http", "id": "me", "name": "我的信息", "url": "https://api.example.com/me",
      "method": "GET", "headers": { "Authorization": "Bearer ${token}" },
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
```

运行:

```shell
php bin/ws-http run smoke.json                    # 文本报告;失败 exit 1
php bin/ws-http run a.json b.json --format=json   # JSON 报告供 CI
php bin/ws-http run smoke.json --var env=prod     # 注入/覆盖变量
```

### 编程式执行

```php
use Ws\Http\Automated\{Runner, ScenarioParser};
use Ws\Http\Automated\RequestFactory;

$runner = new Runner(new RequestFactory());       // 默认工厂 = 真实 HTTP
$report = $runner->run((new ScenarioParser())->parseFile('smoke.json'));

if (!$report->isSuccess()) {
    foreach ($report->steps() as $step) {
        if ($step->status === 'failed') {
            echo $step->stepId, ': ', $step->failReason, PHP_EOL;
        }
    }
}
```

### 替身传输(测试中不出网)

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

场景级 `settings`(步骤级覆盖适用):

| 键 | 默认值 | 含义 |
|---|---|---|
| `timeout` | `"30s"` | 每请求超时(`ms`/`s`/`m` 后缀) |
| `failFast` | `true` | 首败后跳过剩余步骤 |
| `cookieStore` | `"memory"` | `memory` / `none` / `file:<path>` |
| `proxy` / `auth` | — | 由 HTTP 步骤继承 |
| `redirect` | `{follow: true, max: 10}` | `follow:false` 时禁止显式 `max`(V18) |
| `iterate` | — | 批量模式的数据集名(见 [data-driven-testing.zh-CN.md](./data-driven-testing.zh-CN.md)) |

## Dependencies

- **依赖**:core `Request`(经 `RequestFactoryInterface` — 可注入、可替身)、`Assert\AssertionRunner`、`Expression\ExpressionEvaluator`、`Automated\Pause\PauseRegistry`(存在 pause 步骤时)。
- **被依赖**:`bin/ws-http` CLI、`Automated\Dataset\DatasetRunner`(批量模式)、`NanoGpt\Tool\ApiFetchTool`(场景取数形态)、`Ws\Http\Automated\StaticRequestFactory`(注入式传输装配)。

设计详情:[design/16-执行器设计.md](../../../design/16-执行器设计.md)、[design/14-场景脚本Schema%20v2.md](../../../design/14-场景脚本Schema%20v2.md)。
