[English](./plugin-system.md) | [中文版本](./zh-CN/plugin-system.zh-CN.md)

# Plugin System

> Module: `Ws\Http\Plugin` — `PluginRegistry`; contract: `Ws\Http\Contract\PluginInterface`, `PluginContext`, `AuthProviderInterface`; built-ins: `Plugin\OpenAI\*`, `Plugin\WordPress\*`

## Overview

The plugin layer adapts **third-party HTTP services** into ergonomic, semantic PHP clients. A plugin is a *three-piece pattern*:

1. **Auth provider** (`AuthProviderInterface`) — knows how to seal credentials into `RequestOptions` (e.g. Bearer header, Application Password);
2. **Endpoints** — URL-scoped operation objects (e.g. `chat()`, `posts()`);
3. **Semantic methods** — the public surface users actually call (`$client->chat()->create('hi')`).

Registration is minimal by design: **a registry + an interface — no container, no events, no auto-discovery**. Plugins can also contribute cross-cutting extensions (auth providers, comparison operators, extraction sources) through `PluginContext`.

## Key Components

### Contracts

```php
interface PluginInterface
{
    public function name(): string;
    public function register(PluginContext $ctx): void;
}

interface PluginContext
{
    public function addAuth(string $name, AuthProviderInterface $provider): void;
    public function addComparator(string $op, ComparatorInterface $impl): void;   // assertion operators
    public function addExtractor(string $source, ExtractorInterface $impl): void; // extraction sources
    public function share(string $key, object $service): void;                    // shared services
    public function authProviders(): array;
    public function services(): array;
}
```

### `PluginRegistry`

```php
$registry = new PluginRegistry();
$registry->register(new OpenAIPlugin());       // duplicate name → InvalidArgumentException (501)
$registry->register(new WordPressPlugin());
```

### Built-in: OpenAI plugin

```php
final class OpenAI\Client
{
    public function __construct(string $apiKey, ?Request $http = null, string $baseUrl = self::DEFAULT_BASE_URL)
    public function chat(): ChatEndpoint      // POST /chat/completions
    public function models(): ModelsEndpoint  // GET /models
    public function options(): RequestOptions // Bearer-sealed options
    public function baseUrl(): string
}

final class ChatEndpoint
{
    /**
     * @param string|array $prompt string shorthand or full messages array
     * @param array<string, mixed> $extra  extra payload keys (temperature, tools, ...)
     */
    public function create($prompt, string $model = 'gpt-3.5-turbo', array $extra = []): Response;
}
```

Works with any OpenAI-protocol-compatible service — change the base URL (OpenAI, DeepSeek, Qianfan, vLLM…).

### Built-in: WordPress plugin

```php
final class WordPress\Client
{
    public function __construct(string $siteUrl, string $appPassword, ?string $user = null, ?Request $http = null)
    public function posts(): PostsEndpoint     // create/list/update/delete
    public function media(): MediaEndpoint     // upload/list
}
```

Authentication: Application Password (`ApplicationPasswordAuthProvider`).

### Endpoint API Reference

Signatures verified against `src/Ws/Http/Plugin/`:

**OpenAI**

```php
// ChatEndpoint (POST /chat/completions)
public function create($prompt, string $model = 'gpt-3.5-turbo', array $extra = []): Response;
//   $prompt: string shorthand → [['role' => 'user', 'content' => $prompt]]
//   $extra:  payload keys merged at top level (temperature, max_tokens, tools, ...)

// ModelsEndpoint (GET /models)
public function list(): Response;
```

**WordPress**

```php
// PostsEndpoint
public function list(array $query = []): Response;                                  // WP_Query args
public function get(int $id): Response;
public function create(string $title, string $content, string $status = 'draft'): Response;
public function update(int $id, array $fields): Response;                            // partial fields
public function delete(int $id): Response;

// MediaEndpoint
public function upload(string $filepath, array $extra = []): Response;               // $extra: title 等 WP attachment 字段
```

All endpoint methods return the core `Response` — JSON auto-decoding and `Watcher` assertions apply unchanged.

## Usage Examples

### OpenAI-compatible chat

```php
use Ws\Http\Plugin\OpenAI\Client;

$client = new Client($apiKey, null, 'https://api.deepseek.com/v1');

$response = $client->chat()->create(
    'Reply with exactly one word: pong',   // string shorthand → messages array
    'deepseek-chat'
);

echo $response->body->choices[0]->message->content;
echo $response->body->usage->total_tokens;
```

### Full messages array + sampling parameters

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

### WordPress publishing

```php
use Ws\Http\Plugin\WordPress\Client;

$wp = new WordPress\Client('https://blog.example.com', $appPassword, 'admin');
$wp->posts()->create('Hello', 'First post body');
$wp->media()->upload('/tmp/photo.jpg', ['title' => 'My photo']);
```

### Writing a custom plugin

```php
use Ws\Http\Contract\{PluginInterface, PluginContext};

final class WeChatPlugin implements PluginInterface
{
    public function name(): string { return 'wechat'; }

    public function register(PluginContext $ctx): void
    {
        $ctx->addAuth('wechat-sign', new WeChatSignAuthProvider()); // custom credential sealing
        $ctx->share('client', new WeChatClient());                  // semantic client for consumers
    }
}
```

## Configuration

- Plugins are configured through their constructors (keys, base URLs, injected `Request`); there is no global plugin configuration file.
- Note: plugin auth ↔ scenario `auth` field interop (`{"type": "plugin:wechat-sign"}`) is reserved in design/17, not yet implemented.

## Dependencies

- **Depends on**: core `Request`/`RequestOptions` (all plugin clients are core consumers), `Contract\*` interfaces.
- **Depended on by**: `NanoGpt\Transport\OpenAiCompatibleTransport` (composes `Plugin\OpenAI\Client` for the agent transport layer — composition, not inheritance).

Design details: [design/17-Plugin层设计.md](../../design/17-Plugin层设计.md).
