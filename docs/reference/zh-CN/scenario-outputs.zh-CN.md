[English](../scenario-outputs.md) | [中文版本](./scenario-outputs.zh-CN.md)

# 场景作为取数函数(场景输出契约)

> 模块:`Ws\Http\Automated\ScenarioOutput`;Schema 扩展在 `Ws\Http\Automated\ScenarioParser`(规则 V19);消费方:`NanoGpt\Tool\ApiFetchTool`

## Overview

除了验证,场景还能充当**取数函数**:定义变量 → 跑一段(可能多步的)流程 → 返回**预先声明的输出变量集**作为 JSON 结果集。过程可以任意复杂;*签名只有 `outputs`*。

```jsonc
{
  "id": "get-weather", "name": "查天气",
  "outputs": ["city", "temp"],                     // ← 唯一新字段:结果集签名
  "variables": [ { "name": "cityCode", "value": "440100" } ],
  "steps": [
    { "type": "http", "id": "s1", "url": "https://api.example.com/weather?code=${cityCode}",
      "method": "GET",
      "extract": [ { "var": "city", "source": "json", "path": "$.city" },
                   { "var": "temp", "source": "json", "path": "$.data.temp" } ] }
  ]
}
```

语义:

- **键序 = 声明顺序**(稳定,消费方/测试可靠);
- **缺失键不出现**(`onMissing=fail` 的变量到不了结果集——结果集完整性以运行成功为前提);
- **敏感值保持脱敏**——结果集继承已脱敏的 `Report::variables()` 快照;取数出口不构成脱敏旁路;
- 引擎**不**检查 `Report::isSuccess()`——那是调用方的策略决策(重试 / 报错 / 部分读取)。

## Key Components

### `ScenarioOutput`

```php
final class ScenarioOutput
{
    /**
     * @param string[] $outputs 声明的输出(空数组 → 抛出)
     * @return array<string, mixed> 键序 = 声明顺序;未声明/缺失键不出现
     * @throws AutomatedException 630 outputs 为空时(调用方误用,编程期暴露)
     */
    public function extract(Report $report, array $outputs): array;
}
```

### Schema 规则 V19

`outputs` 必须是非空字符串数组,元素唯一且命名合法;每个名字必须**可产出**——出现在 `variables` 声明中,或由某步骤的 `extract[].var` / pause `var` 产出。这防止拼错的名字静默返回空集。违例在解析期失败(402,含 JSON Pointer)。`outputs` 与 `settings.iterate` 正交可组合(每条批次记录产出自己的结果集)。

### 编程式使用

```php
$result = (new ScenarioOutput())->extract($report, $scenario->outputs);
// → ["city" => "广州", "temp" => "26C"]
```

## Usage Examples

### 两行取数函数

```php
use Ws\Http\Automated\{Runner, ScenarioParser, ScenarioOutput, RequestFactory};

$scenario = (new ScenarioParser())->parseFile('get-weather.json');
$report   = (new Runner(new RequestFactory()))->run($scenario);

if ($report->isSuccess()) {
    $weather = (new ScenarioOutput())->extract($report, $scenario->outputs);
    echo $weather['city'], ' ', $weather['temp'], PHP_EOL;
}
```

### 经 agent 工具(`api_fetch` 场景形态)

NanoGpt 的 `api_fetch` 工具以场景文件为形态 B:

```json
{ "scenario": "/abs/path/get-weather.json", "vars": {"cityCode": "440300"}, "outputs": ["city"] }
```

- `vars` — 仅覆盖**已声明**变量(未声明名忽略;签名不可被劫持);
- `outputs` — 声明输出的**收窄子集**(绝不扩充;未声明名被拒绝);
- 成功响应:`status: ok` + JSON 结果集;失败:`status: failed` + `failedSteps`(id/name/reason)供归因;
- 安全面:场景内每个 HTTP URL 在执行**前**对照工具的 host 白名单校验(拒绝发生时不发任何请求)。

单请求形态(`{url, extract[]}`)作为退化形态继续支持。

## Configuration

Schema 之外无配置。`outputs` 声明即全部契约——"过程复杂度没有上限,签名只有 outputs"。

## Dependencies

- **依赖**:`Automated\Report`(脱敏后的终态变量)、`Automated\ScenarioParser`(V19 校验)。
- **被依赖**:`NanoGpt\Tool\ApiFetchTool`(场景形态)、编程式消费方。

设计详情:[design/27-场景输出契约设计.md](../../../design/27-场景输出契约设计.md)。
