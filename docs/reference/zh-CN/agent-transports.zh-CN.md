[English](../agent-transports.md) | [中文版本](./agent-transports.zh-CN.md)

# NanoGpt — 传输层(多厂商)

> 模块:`Ws\Http\NanoGpt\Transport` — `ChatTransportInterface`、`OpenAiCompatibleTransport`(抽象)、厂商类、`ChatResult`
> 设计:两个维度——**A. 协议族**(抽象基类)× **B. 厂商**(每厂商一个具体类)

## Overview

传输层拥有全部厂商相关物:认证、端点、请求体翻译、响应解析、重试语义、错误分类。编排层(`Agent`)对这些一无所知。

**验收规则**:编排层源码出现任何厂商名即缺陷。反之,新增厂商绝不触碰既有文件。

两个维度、两种机制:

- **维度 A — 协议族**:差异是*结构性的*(认证头、端点、消息形态、解析)→ 由**抽象基类**(`OpenAiCompatibleTransport`)承载;
- **维度 B — 厂商**:差异是*参数与知识*(base URL、默认模型、怪癖字段)→ 由**每厂商一个具体类**承载(知识有家;漂移修一个文件;怪癖覆写 protected 槽)。

## Key Components

### `ChatTransportInterface`(编排层唯一契约)

```php
interface ChatTransportInterface
{
    /**
     * @param array<int, array<string, mixed>> $messages OpenAI wire 格式(编排层通用语;
     *        协议级厂商在内部自行翻译)
     * @param array<string, mixed> $options 采样参数(max_tokens/temperature)
     *        + 重试策略 + 请求级 model
     * @throws AgentException 携带语义分类码(620–624)
     */
    public function chat(array $messages, array $options = []): ChatResult;

    /**
     * 能力档案:模型目录 + 静态能力声明。
     * @return array{models: array<int, ModelInfo>, contextWindow: ?int, maxOutputTokens: ?int}
     */
    public function capabilities(): array;
}
```

### `OpenAiCompatibleTransport`(抽象基类 — 维度 A)

承载协议族共性;**基类内部禁止厂商分支**:

- 请求组装(组合 `Plugin\OpenAI\Client`——不重复认证/HTTP);
- 响应解析 → `ChatResult`(统一;保留 `reasoning_content`);
- **完整重试语义**;**错误分类 620–624**;
- protected 槽(差异点):

```php
protected const BASE_URL = 'https://api.openai.com/v1';   // 厂商子类覆写
protected const DEFAULT_MODEL = 'gpt-4o-mini';
protected const TIMEOUT = 60;
protected const RETRY_ON_STATUS = [429, 500, 502, 503, 504];

protected function defaultModel(): string                  // 槽(capabilities / 兜底)
protected function extraOptions(array $options): array     // 采样参数透传槽
protected function isParseable(Response $response): bool   // 624 判定槽
protected function modelFromEntry(object $entry): ?ModelInfo // 目录槽:过滤/归一(null = 过滤掉)
```

### 厂商类(维度 B)

| 类 | Base URL | 默认模型 |
|---|---|---|
| `OpenAiTransport` | `https://api.openai.com/v1` | `gpt-4o-mini` |
| `DeepSeekTransport` | `https://api.deepseek.com/v1` | `deepseek-chat` |
| `QwenTransport` | `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen-turbo` |
| `KimiTransport` | `https://api.kimi.com/coding/v1` | `kimi-coding` |
| `MoonshotTransport` | `https://api.moonshot.cn/v1` | `moonshot-v1-8k` |
| `MiniMaxTransport` | `https://api.minimax.chat/v1` | `abab6.5s-chat` |
| `OrcaRouterTransport` | `https://api.orcarouter.ai/v1` | `deepseek/deepseek-v4-flash-free`(+ free 模型目录过滤) |
| `GenericOpenAiTransport` | 经 config | 经 config(`default_model`)——冷门 OpenAI 兼容服务的逃生门 |

多数厂商类只有 3–5 行(常量覆写)。`OrcaRouterTransport` 演示怪癖槽:其 `modelFromEntry()` 实现 free 过滤与其真实 API 观察到的对象形 pricing 归一。

### 重试语义(完整、一等公民)

```php
$options['retry'] = [
    'max'        => 0,                        // 最大重试次数;默认 0 = 关闭
    'delay_ms'   => 1000,                     // 首次退避
    'multiplier' => 2.0,                      // 指数:delay_ms * multiplier^attempt
    'on_status'  => [429, 500, 502, 503, 504],// 可重试状态码
];
// Retry-After 响应头优先于计算出的退避。
// 重试耗尽 → 最后一次错误进错误分类。
```

### 错误分类(620–624 传输子码)

| 码 | 含义 | 触发 |
|---|---|---|
| 620 | 认证失败 | HTTP 401/403 |
| 621 | 被限流 | HTTP 429(重试耗尽后) |
| 622 | 服务器错误 | HTTP 5xx(重试耗尽后) |
| 623 | 超时 | 连接/执行超时(cURL errno 侧) |
| 624 | 异常响应 | 不可重试状态码,或 2xx 结构不可解析 |

### `ChatResult`(统一响应值对象)

```php
public static function fromBody(?object $body): self;   // 宽容解析——缺形态 → 空值
public function content(): string
public function reasoningContent(): ?string             // deepseek-r1 式思考字段
public function finishReason(): ?string                 // stop / length / tool_calls …
public function toolCalls(): array   // [{id, name, arguments, wire}] — wire 原样保留供回填
public function usage(): array
public function hasUsage(): bool
```

## Usage Examples

### 换厂商 = 换类

```php
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Transport\{DeepSeekTransport, QwenTransport, GenericOpenAiTransport};

$agent = new Agent(new DeepSeekTransport(['api_key' => $key]), 'deepseek-chat');
// ... 或任意其他厂商——其余什么都不用变:
$agent = new Agent(new QwenTransport(['api_key' => $key]), 'qwen-max');
$agent = new Agent(new GenericOpenAiTransport([
    'api_key'       => $key,
    'base_url'      => 'https://my-gateway.internal/v1',
    'default_model' => 'team-default',
]), 'team-default');
```

### 请求级模型覆盖 + 重试

```php
$result = $transport->chat(
    [['role' => 'user', 'content' => 'hi']],
    [
        'model'      => 'deepseek-reasoner',   // 本次调用覆盖厂商默认
        'max_tokens' => 512,
        'retry'      => ['max' => 2, 'delay_ms' => 500],
    ]
);
echo $result->content();
echo $result->usage()['total_tokens'];
```

### 4 行接入自定义厂商

```php
use Ws\Http\NanoGpt\Transport\OpenAiCompatibleTransport;

final class MyGatewayTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://gateway.mycompany.internal/v1';
    protected const DEFAULT_MODEL = 'corp-assistant';
}
```

### 模型目录(capabilities)

```php
$capabilities = $transport->capabilities();   // GET /models
foreach ($capabilities['models'] as $modelInfo) {
    echo $modelInfo->id(), ' — ', $modelInfo->name(), PHP_EOL;  // ModelInfo 属性模型
}
```

## Configuration

构造 config 数组(所有厂商类同形):

| 键 | 必填 | 默认 | 含义 |
|---|---|---|---|
| `api_key` | 是 | — | 凭据(Bearer) |
| `base_url` | 否 | 类 `BASE_URL` | 端点覆盖 |
| `timeout` | 否 | `TIMEOUT`(60 秒) | 请求超时 |
| `http` | 否 | new `Request` | **测试注入缝隙**(`Request` 实例) |
| `default_model` | 仅 Generic | `gpt-4o-mini` | 泛用传输的兜底模型 |

## Dependencies

- **依赖**:`Plugin\OpenAI\Client`(组合——plugin 层的、面向人的业务客户端)、core `Request`/`Response`。
- **被依赖**:`NanoGpt\Agent`(经接口)、`examples/cc-gpt` 壳装配(`settings.adapter` → 厂商类)。

设计详情:[design/28-厂商接入层设计.md](../../../design/28-厂商接入层设计.md)。
