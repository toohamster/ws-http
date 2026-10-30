[English](../error-codes.md) | [中文版本](./error-codes.zh-CN.md)

# 错误码参考

> 跨模块速查。ws-http 的每个 `Exception` 都携带段码,标识所属层与失败类别。
> 段:`1xx` 传输 · `2xx` 表达式 · `3xx` 断言 · `4xx` 场景引擎 · `5xx` 插件 · `6xx` NanoGpt

## 码表

### 1xx — 传输(`RequestException`)

| 码 | 含义 | 典型来源 |
|---|---|---|
| curl errno | 传输失败;码**就是 cURL errno**(如 6 = 无法解析主机、7 = 无法连接、28 = 超时) | `Request::send()` 中 `curl_errno() !== 0` 时 |

### 2xx — 表达式(`ExpressionException`)

| 码 | 含义 |
|---|---|
| 201 | 语法错误(消息含出错位置) |
| 202 | 空集按错误处理(调用方显式选严格模式) |
| 203 | 类型不匹配 |

### 3xx — 断言(`AssertionException`)

| 码 | 含义 |
|---|---|
| 301 | 状态码不符 |
| 302 | 头缺失 |
| 303 | 头值不符 |
| 304 | 体断言失败 |
| 305 | JSON 断言失败 |

### 4xx — 场景引擎(`AutomatedException`)

| 码 | 含义 | 抛出者 |
|---|---|---|
| 401 | 场景 JSON 非法 / 文件不可读 | `ScenarioParser::parse/parseFile`;Cookie 存储写失败 |
| 402 | 字段校验失败(V1–V19;消息含 JSON Pointer) | `ScenarioParser` |
| 403 | 步骤类型非法(或 Cookie 存储文件系统写被拒) | 解析器 / `FileCookieStore::persist` |
| 404 | 数据模式非法 | 解析器 |
| 405 | 提取失败(`onMissing=fail`) | `VarExtractor` |
| 406–408 | pause 取值失败(来源专属:stdin/env/poll) | `Pause\*` |
| 420–422 | 数据集失败(加载/形态/一致性) | `Dataset\*` |

### 5xx — 插件

| 码 | 含义 |
|---|---|
| 501 | 插件注册冲突(重名) |
| 502 | 认证配置错误(各插件可定义子码) |

### 6xx — NanoGpt(`AgentException`)

| 码 | 含义 | 抛出者 |
|---|---|---|
| 601 | 超 maxTurns 未产出文本 | `Agent::run` |
| 602 | 未知名工具 | `ToolRegistry::get` |
| 603 | 工具重名注册 | `ToolRegistry::register` |
| 604 | 对话响应结构异常 | 旧结构守卫(解析现收口于传输层 → 624) |
| 605 | 截断点不是 user 边界 | `Conversation::truncateFrom` |
| 610 | 沙箱路径越界(realpath 包含校验被破) | `Sandbox::resolve` |
| 611 | 会话快照版本未知 | `Conversation::restore`(引导:开新会话) |
| 612 | 压缩失败(空段 / LLM 失败 / 空摘要;原消息不动) | `LlmCompactor` |
| 613 | 描述/技能文件非法(重名、缺字段、schema 违例;消息含文件名) | `DescriptorToolLoader`、`SkillLoader`、`SkillDefinition` |
| 614 | 参数槽校验失败(缺必填)——以工具/命令返回呈现,不抛 | `SkillDefinition::render`、`CommandToolBase` |
| 620 | 传输认证失败(HTTP 401/403) | `OpenAiCompatibleTransport` |
| 621 | 传输被限流(HTTP 429,重试耗尽) | `OpenAiCompatibleTransport` |
| 622 | 传输服务器错误(HTTP 5xx,重试耗尽) | `OpenAiCompatibleTransport` |
| 623 | 传输超时(连接/执行) | `OpenAiCompatibleTransport` |
| 624 | 传输异常响应(不可重试状态码或 2xx 不可解析) | `OpenAiCompatibleTransport` |
| 630 | 场景未声明 outputs(取数函数误用) | `ScenarioOutput::extract` |

## Usage Examples

### 按分类码分支(编排层)

```php
use Ws\Http\NanoGpt\AgentException;

try {
    foreach ($agent->run($input) as $event) { /* 渲染 */ }
} catch (AgentException $e) {
    switch ($e->getCode()) {
        case 621: hintRateLimit(); break;   // 建议等待或压缩
        case 620: promptRelogin(); break;
        default:  echo $e->getMessage(); break;
    }
}
```

### 解析期 vs 运行期失败

```php
use Ws\Http\Automated\{ScenarioParser, Runner};
use Ws\Http\Automated\AutomatedException;

try {
    $scenario = (new ScenarioParser())->parseFile('flow.json'); // 这里 401/402 = 编写错误
} catch (AutomatedException $e) {
    exit(2); // CLI 约定:解析错误
}

$report = (new Runner($factory))->run($scenario);              // 这里 405+ = 运行期数据问题
```

## 说明

- 码是**稳定标识**,不是展示文本——分支、匹配、日志都以码为准;
- 614(以及工具语境的 602)按设计作为工具返回码而非抛出异常:工具执行失败以字符串回流给模型;
- 新码先落设计文档([design/10 §5](../../../design/10-总体架构设计.md))再实现——本页跟踪的是已实现集。
