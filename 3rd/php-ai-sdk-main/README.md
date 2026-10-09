# PHP AI SDK

> 轻量、多驱动、标准化输出的 PHP AI 接入 SDK

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![CI](https://github.com/SnowmanNunu/php-ai-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/SnowmanNunu/php-ai-sdk/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/snowman/ai)](https://packagist.org/packages/snowman/ai)

## Features

- **多驱动支持**：Claude、OpenAI、DeepSeek，一行代码切换
- **标准化 DTO**：统一响应结构，屏蔽各 API 差异
- **Laravel 友好**：ServiceProvider + Facade + 配置文件，开箱即用
- **流式输出**：原生 Generator 支持，实时获取响应
- **异常映射**：HTTP 状态码智能映射到具体异常类

## 快速上手

### 安装

```bash
composer require snowman/ai
```

### Laravel 配置

```bash
# 发布配置文件
php artisan vendor:publish --tag=ai-config
```

### .env 配置

```dotenv
AI_DRIVER=claude

CLAUDE_API_KEY=sk-ant-xxxxxxxx
OPENAI_API_KEY=sk-xxxxxxxx
DEEPSEEK_API_KEY=sk-xxxxxxxx
```

### 使用

```php
use SnowmanNunu\Ai\Laravel\Facades\Ai;

$response = Ai::chat([
    ['role' => 'user', 'content' => '用 PHP 写一个冒泡排序'],
]);

echo $response->content;             // 模型回复文本
echo $response->usage->totalTokens; // 总 token 用量
echo $response->model;               // 实际模型名
```

### 调试命令

```bash
# 使用默认驱动测试连通性
php artisan ai:test

# 指定驱动和提示词
php artisan ai:test --driver=kimi --prompt="你好"

# 流式输出（可观察 reasoning_content 增量）
php artisan ai:test --driver=kimi --stream
```

## 驱动配置

### Claude

```dotenv
AI_DRIVER=claude
CLAUDE_API_KEY=sk-ant-xxxxxxxx
CLAUDE_MODEL=claude-sonnet-4-6
```

### OpenAI

```dotenv
AI_DRIVER=openai
OPENAI_API_KEY=sk-xxxxxxxx
OPENAI_MODEL=gpt-4o-mini
OPENAI_BASE_URL=https://api.openai.com/v1
```

### DeepSeek

```dotenv
AI_DRIVER=deepseek
DEEPSEEK_API_KEY=sk-xxxxxxxx
DEEPSEEK_MODEL=deepseek-chat
```

### 智谱 AI (GLM)

```dotenv
AI_DRIVER=zhipu
ZHIPU_API_KEY=xxxxxxxx
ZHIPU_MODEL=glm-4-flash
ZHIPU_BASE_URL=https://open.bigmodel.cn/api/paas/v4
```

### 通义千问 (Qwen)

```dotenv
AI_DRIVER=qwen
QWEN_API_KEY=xxxxxxxx
QWEN_MODEL=qwen-turbo
QWEN_BASE_URL=https://dashscope.aliyuncs.com/compatible-mode/v1
```

### 文心一言 (Wenxin)

```dotenv
AI_DRIVER=wenxin
WENXIN_API_KEY=xxxxxxxx
WENXIN_MODEL=ernie-4.0-8k-latest
WENXIN_BASE_URL=https://qianfan.baidubce.com/v2
```

### 月之暗面 (Moonshot)

```dotenv
AI_DRIVER=moonshot
MOONSHOT_API_KEY=xxxxxxxx
MOONSHOT_MODEL=moonshot-v1-8k
MOONSHOT_BASE_URL=https://api.moonshot.cn/v1
```

### MiniMax

```dotenv
AI_DRIVER=minimax
MINIMAX_API_KEY=xxxxxxxx
MINIMAX_MODEL=abab6.5s-chat
MINIMAX_BASE_URL=https://api.minimax.chat/v1
```

### Kimi for Coding

```dotenv
AI_DRIVER=kimi
KIMI_API_KEY=xxxxxxxx
KIMI_MODEL=kimi-coding
KIMI_BASE_URL=https://api.kimi.com/coding/v1/
```

### Xiaomi MiMo

```dotenv
AI_DRIVER=xiaomi-mimo
XIAOMI_MIMO_API_KEY=xxxxxxxx
XIAOMI_MIMO_MODEL=mimo-v2.5-pro
XIAOMI_MIMO_BASE_URL=https://token-plan-cn.xiaomimimo.com/v1
```

## 高级配置

你可以在任意驱动的配置中加入以下选项：

```php
'drivers' => [
    'kimi' => [
        'api_key' => env('KIMI_API_KEY'),
        'model' => 'kimi-coding',
        // 代理
        'proxy' => 'http://127.0.0.1:7890',
        // 自定义 Guzzle HandlerStack（用于日志、签名等）
        'handler' => $myHandlerStack,
        // 重试
        'retry' => 3,
        'retry_delay' => 1000,          // 首次重试等待毫秒
        'retry_multiplier' => 2.0,      // 指数退避倍数
        'retry_on_status' => [429, 500, 502, 503, 504],
    ],
],
```

- `handler`：直接注入 `GuzzleHttp\HandlerStack` 或 callable，方便接入请求日志、监控、签名中间件。
- `proxy`：HTTP 代理地址，透传给 Guzzle。
- `retry`：最大重试次数，默认 `0`（不重试）。遇到 `retry_on_status` 中的状态码或网络连接异常时会自动重试。
- 如果响应头包含 `Retry-After`，会优先使用该时间（秒 → 毫秒）。

## Tools / Vision / Reasoning

### Function Calling

```php
use SnowmanNunu\Ai\Support\Tool;

$tool = Tool::define('get_weather', '获取指定城市天气', [
    'type' => 'object',
    'properties' => [
        'city' => ['type' => 'string', 'description' => '城市名'],
    ],
    'required' => ['city'],
]);

$response = Ai::chat(
    [['role' => 'user', 'content' => '北京天气怎么样？']],
    ['tools' => [$tool], 'tool_choice' => 'auto']
);

if ($response->toolCalls) {
    foreach ($response->toolCalls as $call) {
        echo $call->name;       // get_weather
        echo $call->arguments;  // {"city":"北京"}
    }
}
```

> Claude 驱动会自动把上面的 OpenAI 风格 `Tool::define()` 转换为 Anthropic 格式，返回的 `toolCalls` 结构保持一致。

### Vision（图像输入）

```php
use SnowmanNunu\Ai\Support\VisionMessage;

$messages = [
    ['role' => 'user', 'content' => [
        VisionMessage::text('描述这张图片'),
        VisionMessage::image('https://example.com/cat.jpg'),
        // 或传 base64
        // VisionMessage::image('data:image/png;base64,xxx', 'high'),
    ]],
];

$response = Ai::chat($messages);
```

Claude 只支持 base64 图片，可直接用 `VisionMessage::claudeImage()`：

```php
$messages = [
    ['role' => 'user', 'content' => [
        VisionMessage::text('描述这张图片'),
        VisionMessage::claudeImage($base64String, 'image/png'),
    ]],
];
```

> 如果你使用 `VisionMessage::image('data:image/png;base64,xxx')`，Claude 驱动会自动转换成它需要的格式；纯 HTTP URL 图片暂不支持。

### Reasoning Content

部分模型（如 DeepSeek、Kimi for Coding）会返回推理过程：

```php
$response = Ai::chat([['role' => 'user', 'content' => '9.11 和 9.8 哪个大？']]);

echo $response->content;           // 最终答案
echo $response->reasoningContent;  // 模型内部推理文本（可能为 null）
```

流式输出时，`StreamChunk` 也支持 `reasoningContent` 与 `toolCalls`：

```php
foreach (Ai::stream($messages) as $chunk) {
    echo $chunk->reasoningContent ?? '';
    echo $chunk->content;

    if ($chunk->toolCalls) {
        foreach ($chunk->toolCalls as $call) {
            echo $call->name;       // get_weather
            echo $call->arguments;  // {"city":"北京"}
        }
    }
}
```

## API 参考

### chat()

```php
Ai::chat(array $messages, array $options = []): AiResponse
```

**参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `messages` | `array` | 消息数组，格式：`[['role' => 'user|assistant|system', 'content' => '...']]` |
| `options.model` | `string` | 模型名称 |
| `options.max_tokens` | `int` | 最大输出 token 数 |
| `options.temperature` | `float` | 温度参数 |

**返回 `AiResponse`：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `content` | `string` | 模型输出文本 |
| `model` | `string` | 实际使用的模型名 |
| `usage` | `TokenUsage` | token 用量 |
| `driver` | `string` | 驱动标识 |
| `raw` | `array` | 原始 API 响应 |
| `toolCalls` | `ToolCall[]\|null` | 模型请求调用的工具 |
| `reasoningContent` | `string\|null` | 模型推理过程文本 |
| `finishReason` | `string\|null` | 结束原因（stop / tool_calls / length 等） |
| `id` | `string\|null` | 响应 ID |
| `created` | `int\|null` | 响应时间戳 |

### stream()

```php
Ai::stream(array $messages, array $options = []): Generator<StreamChunk>
```

**返回 `StreamChunk`：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `content` | `string` | 本次增量文本 |
| `done` | `bool` | 是否结束 |
| `reasoningContent` | `string\|null` | 本次增量推理文本 |
| `toolCalls` | `ToolCall[]\|null` | 本次增量中的工具调用（通常在模型生成完工具参数后一次性返回） |

> 流式解析兼容 `\n`、`\r\n`、`\r` 三种换行，并会自动跳过格式错误的 SSE 数据行。

## 切换驱动

```php
$response = Ai::driver('openai')->chat([...]);
$response = Ai::driver('deepseek')->chat([...]);
```

## 自定义驱动

```php
Ai::extend('my-model', function (array $config) {
    return new MyCustomDriver($config);
});

Ai::driver('my-model')->chat([...]);
```

## 异常处理

```php
use SnowmanNunu\Ai\Exceptions\AiRateLimitException;
use SnowmanNunu\Ai\Exceptions\AiException;

try {
    $response = Ai::chat([...]);
} catch (AiRateLimitException $e) {
    // 限流：等待后重试
    sleep(5);
} catch (AiException $e) {
    // 其他 AI 异常
    logger()->error('AI error', ['message' => $e->getMessage()]);
}
```

### 异常类型

| 异常类 | 触发条件 |
|--------|----------|
| `AiAuthException` | API Key 无效或缺失（HTTP 401） |
| `AiRateLimitException` | 请求频率超限（HTTP 429） |
| `AiTimeoutException` | 连接或读取超时 |
| `AiServerException` | 模型端 5xx 错误 |
| `AiDriverNotFoundException` | 指定驱动未注册 |

## ThinkPHP 6 使用

### 安装与发布配置

```bash
composer require snowman/ai

# 配置文件会自动发布到 config/ai.php
# 如需手动执行：
php think vendor:publish
```

### 使用容器或门面

```php
use SnowmanNunu\Ai\ThinkPHP\Facades\Ai;

// 门面
$response = Ai::chat([
    ['role' => 'user', 'content' => '用 PHP 写一个冒泡排序'],
]);

// 容器
$response = app('ai')->chat([
    ['role' => 'user', 'content' => '用 PHP 写一个冒泡排序'],
]);
```

### 调试命令

```bash
# 使用默认驱动测试连通性
php think ai:test

# 指定驱动和提示词
php think ai:test --driver=kimi --prompt="你好"

# 流式输出（可观察 reasoning_content 增量）
php think ai:test --driver=kimi --stream
```

## 纯 PHP 使用

```php
use SnowmanNunu\Ai\AiManager;

$manager = new AiManager([
    'default' => 'claude',
    'drivers' => [
        'claude' => ['api_key' => 'sk-ant-xxxxxxxx'],
    ],
]);

$response = $manager->chat([['role' => 'user', 'content' => 'Hello']]);
```

## 测试

```bash
composer test            # 运行全部测试
composer test:unit       # 仅单元测试
composer test:coverage   # 生成覆盖率报告
composer lint            # 代码格式化检查
```

## Contributing

欢迎提交 Issue 和 PR！

## License

MIT
