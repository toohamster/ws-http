[English](../plugin-system.md) | [中文版本](./plugin-system.zh-CN.md)

# 插件系统

> 模块:`Ws\Http\Plugin` — `PluginRegistry`;契约:`Ws\Http\Contract\PluginInterface`、`PluginContext`、`AuthProviderInterface`;内建:`Plugin\OpenAI\*`、`Plugin\WordPress\*`

## Overview

插件层把**三方 HTTP 服务**适配成符合人体工程学的语义化 PHP 客户端。一个插件是*三件套模式*:

1. **认证提供器**(`AuthProviderInterface`)— 知道如何把凭据封进 `RequestOptions`(如 Bearer 头、Application Password);
2. **端点** — 以 URL 为界的操作对象(如 `chat()`、`posts()`);
3. **语义化方法** — 用户实际调用的公开面(`$client->chat()->create('hi')`)。

注册机制刻意最小化:**一个注册表 + 一个接口——无容器、无事件、无自动发现**。插件还可经 `PluginContext` 贡献横切扩展(认证提供器、比较操作符、提取 source)。

## Key Components

### 契约

```php
interface PluginInterface
{
    public function name(): string;
    public function register(PluginContext $ctx): void;
}

interface PluginContext
{
    public function addAuth(string $name, AuthProviderInterface $provider): void;
    public function addComparator(string $op, ComparatorInterface $impl): void;   // 断言操作符
    public function addExtractor(string $source, ExtractorInterface $impl): void; // 提取 source
    public function share(string $key, object $service): void;                    // 共享服务
    public function authProviders(): array;
    public function services(): array;
}
```

### `PluginRegistry`

```php
$registry = new PluginRegistry();
$registry->register(new OpenAIPlugin());       // 重名 → InvalidArgumentException(501)
$registry->register(new WordPressPlugin());
```

### 内建:OpenAI 插件

```php
final class OpenAI\Client
{
    public function __construct(string $apiKey, ?Request $http = null, string $baseUrl = self::DEFAULT_BASE_URL)
    public function chat(): ChatEndpoint      // POST /chat/completions
    public function models(): ModelsEndpoint  // GET /models
    public function options(): RequestOptions // Bearer 封印的 options
    public function baseUrl(): string
}

final class ChatEndpoint
{
    /**
     * @param string|array $prompt 字符串简写或完整 messages 数组
     * @param array<string, mixed> $extra  额外载荷键(temperature、tools…)
     */
    public function create($prompt, string $model = 'gpt-3.5-turbo', array $extra = []): Response;
}
```

任何 OpenAI 协议兼容服务皆可用——换 base-url 即接入(OpenAI、DeepSeek、千帆、vLLM…)。

### 内建:WordPress 插件

```php
final class WordPress\Client
{
    public function __construct(string $siteUrl, string $appPassword, ?string $user = null, ?Request $http = null)
    public function posts(): PostsEndpoint     // create/list/update/delete
    public function media(): MediaEndpoint     // upload/list
}
```

认证方式:Application Password(`ApplicationPasswordAuthProvider`)。

## Usage Examples

### OpenAI 兼容对话

```php
use Ws\Http\Plugin\OpenAI\Client;

$client = new Client($apiKey, null, 'https://api.deepseek.com/v1');

$response = $client->chat()->create(
    'Reply with exactly one word: pong',   // 字符串简写 → messages 数组
    'deepseek-chat'
);

echo $response->body->choices[0]->message->content;
echo $response->body->usage->total_tokens;
```

### 完整 messages 数组 + 采样参数

```php
$response = $client->chat()->create(
    [
        ['role' => 'system', 'content' => 'You are terse.'],
        ['role' => 'user',   'content' => 'Summarize HTTP status codes.'],
    ],
    'gpt-4o-mini',
    ['temperature' => 0.2, 'max_tokens' => 256]
);
```

### WordPress 发布

```php
use Ws\Http\Plugin\WordPress\Client;

$wp = new WordPress\Client('https://blog.example.com', $appPassword, 'admin');
$wp->posts()->create('Hello', 'First post body');
$wp->media()->upload('/tmp/photo.jpg', 'image/jpeg');
```

### 编写自定义插件

```php
use Ws\Http\Contract\{PluginInterface, PluginContext};

final class WeChatPlugin implements PluginInterface
{
    public function name(): string { return 'wechat'; }

    public function register(PluginContext $ctx): void
    {
        $ctx->addAuth('wechat-sign', new WeChatSignAuthProvider()); // 自定义凭据封印
        $ctx->share('client', new WeChatClient());                  // 供消费方使用的语义客户端
    }
}
```

## Configuration

- 插件经构造器配置(密钥、base-url、注入的 `Request`);没有全局插件配置文件;
- 注:plugin 认证 ↔ 场景 `auth` 字段打通(`{"type": "plugin:wechat-sign"}`)在 design/17 中预留,尚未实现。

## Dependencies

- **依赖**:core `Request`/`RequestOptions`(所有插件客户端都是 core 消费方)、`Contract\*` 接口。
- **被依赖**:`NanoGpt\Transport\OpenAiCompatibleTransport`(为 agent 传输层组合 `Plugin\OpenAI\Client`——组合而非继承)。

设计详情:[design/17-Plugin层设计.md](../../../design/17-Plugin层设计.md)。
