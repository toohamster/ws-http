[English](../cookies-and-auth.md) | [中文版本](./cookies-and-auth.zh-CN.md)

# Cookie 与外部凭据注入

> 模块:`Ws\Http\Automated` — `MemoryCookieStore`、`FileCookieStore`、`Pause\PauseRegistry`、`Pause\StdinSource`、`Pause\EnvironmentSource`、`Pause\PollSource`、`Pause\ValueSource`、`Pause\ValueResult`;契约:`Ws\Http\Contract\CookieStoreInterface`

## Overview

两组正交的设施,为真实 API 流程提供有状态支撑:

1. **Cookie 存储** — 场景的传输层 Cookie 罐(`settings.cookieStore`)。罐子遵循 FILE+JAR 语义:每个响应都收集,匹配请求时回放,过期条目剔除;
2. **外部凭据注入** — `pause` 步骤类型。场景可在中途停下,从外部取值(人工输入、环境变量、轮询端点),而不是硬编码凭据。*pause/resume 是流程维度,取值来源是数据维度*——一种步骤类型,可插拔策略。

## Key Components

### Cookie 存储(`settings.cookieStore`)

| 取值 | 实现 | 行为 |
|---|---|---|
| `memory`(默认) | `MemoryCookieStore` | 进程内 jar;主机精确匹配;读取时剔除过期条目;**不做域名后缀匹配**(有意为之,场景范围的简单性) |
| `none` | 匿名空实现 | 不发送不收集 |
| `file:<path>` | `FileCookieStore` | memory 语义 + 持久化:构造时文件存在即加载(Netscape 格式),场景结束时写回(`Runner::run` 调 `persist()`);损坏行容忍;写失败抛 AutomatedException 403 |

```php
interface CookieStoreInterface {
    /** 返回应注入该 URL 的 Cookie 请求头值,无则 null。 */
    public function cookieHeaderFor(string $url): ?string;
    /** 从 Set-Cookie 头收集(任何状态码都调用)。 */
    public function collectFrom(Response $response, string $url): void;
}
```

收集发生在每个请求之后、无论状态码——4xx 响应的 Set-Cookie 也可能有效。

### 暂停步骤与取值来源

步骤形态(V12–V13 校验):

```jsonc
{
  "type": "pause", "id": "approve", "name": "人工审批",
  "var": "approvalCode",          // 必填 — 接收取值的变量
  "from": "stdin",                // 必填 — 已注册的 ValueSource id
  "options": { "message": "输入审批码" },   // 来源专属,标量 map
  "extract": [ ... ]              // 可选 — 对原始值后处理(如 template)
}
```

内建来源(`from` id):

| id | 类 | 行为 |
|---|---|---|
| `stdin` | `Pause\StdinSource` | 交互式终端输入(`options.message`、`options.mask`) |
| `environment` | `Pause\EnvironmentSource` | 读环境变量(`options.env`)或文件(`options.file`) |
| `poll` | `Pause\PollSource` | GET 一个端点直到提取匹配(`options.interval`、`options.timeout`、`options.path`、`options.response`)——时间参数仅 poll 有 |

```php
final class PauseRegistry
{
    public function __construct(array $sources = [], array $pollExtractors = [], array $pollDefaults = [])
    public function register(ValueSource $source): void
    public function has(string $id): bool
    public function get(string $id): ValueSource      // 未知名抛 AutomatedException 407
    public function ids(): array
}

interface ValueSource {
    /** @return ValueResult(值或结构化失败) */
    public function fetch(string $var, array $options): ValueResult;
}
```

未知的 `from` 引用在 Runner 装配期校验(407),不由解析器负责——注册表不是解析器的关注点。

## Usage Examples

### 登录流程中的 Cookie 罐

```jsonc
{
  "id": "cookie-flow", "name": "Cookie 流程",
  "settings": { "cookieStore": "memory" },
  "steps": [
    { "type": "http", "id": "login", "name": "登录",
      "url": "https://api.example.com/login", "method": "POST",
      "body": { "mode": "json", "content": "{\"u\":\"a\",\"p\":\"b\"}" } },
    { "type": "http", "id": "me", "name": "我的信息",
      "url": "https://api.example.com/me", "method": "GET",
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
// "login" 设置的会话 Cookie 在 "me" 上自动回放。
```

### 跨运行的持久化 Cookie

```jsonc
{ "settings": { "cookieStore": "file:.runtime/cookies.jar" }, "...": "..." }
// 首次运行:罐从空(或既有文件)开始,结束时写回。
// 下次运行:从上次离开的地方继续——适合复用已登录会话。
```

### 人工介入的凭据

```jsonc
{
  "type": "pause", "id": "otp", "name": "一次性验证码",
  "var": "otp", "from": "stdin",
  "options": { "message": "输入验证器里的 OTP" }
},
{ "type": "http", "id": "verify", "url": "https://api.example.com/verify?code=${otp}", "method": "GET" }
```

### 等待异步任务完成

```jsonc
{
  "type": "pause", "id": "wait-job", "name": "等待任务",
  "var": "jobStatus", "from": "poll",
  "options": {
    "url": "https://api.example.com/jobs/42",
    "path": "$.status",
    "response": "done",
    "interval": "2s",
    "timeout": "60s"
  }
}
```

## Configuration

- Cookie 行为:仅场景 `settings.cookieStore`;
- 暂停行为:每步 `options`(V13 校验为标量 map / 标量列表);
- 错误码:`406–408` pause 取值失败(4xx 引擎段);`407` 未知来源。

## Dependencies

- **依赖**:core `Response`/`HeaderBag`(Cookie 收集)、`Ws\Http\Expression`(poll 提取)。
- **被依赖**:`Automated\Runner`(每次 run 创建存储;pause 步骤执行)、场景作者(settings/steps)。

设计详情:[design/16-执行器设计.md](../../../design/16-执行器设计.md)(Cookie)、[design/22-外部凭据注入设计.md](../../../design/22-外部凭据注入设计.md)(pause)。
