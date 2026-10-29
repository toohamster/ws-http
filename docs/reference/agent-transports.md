[English](./agent-transports.md) | [中文版本](./agent-transports.zh-CN.md)

# NanoGpt — Transports (Multi-Provider)

> Module: `Ws\Http\NanoGpt\Transport` — `ChatTransportInterface`, `OpenAiCompatibleTransport` (abstract), vendor classes, `ChatResult`
> Design: two dimensions — **A. protocol family** (abstract base) × **B. vendor** (one concrete class each)

## Overview

The transport layer owns everything provider-specific: authentication, endpoints, request-body translation, response parsing, retry semantics, and error classification. The orchestration layer (`Agent`) knows none of it.

**Acceptance rule**: any vendor name appearing in orchestration source is a defect. Conversely, adding a vendor never touches existing files.

Two dimensions, two mechanisms:

- **Dimension A — protocol family**: differences are *structural* (auth header, endpoint, message shape, parsing) → carried by an **abstract base class** (`OpenAiCompatibleTransport`);
- **Dimension B — vendor**: differences are *parameters and knowledge* (base URL, default model, quirk fields) → carried by **one concrete class per vendor** (knowledge has a home; drift fixes one file; quirks override protected slots).

## Key Components

### `ChatTransportInterface` (the only orchestration-facing contract)

```php
interface ChatTransportInterface
{
    /**
     * @param array<int, array<string, mixed>> $messages OpenAI wire format (orchestration lingua franca;
     *        protocol-level vendors translate internally)
     * @param array<string, mixed> $options sampling params (max_tokens/temperature)
     *        + retry policy + request-level model
     * @throws AgentException with a semantic classification code (620–624)
     */
    public function chat(array $messages, array $options = []): ChatResult;

    /**
     * Capability profile: model catalog + static capability declaration.
     * @return array{models: array<int, ModelInfo>, contextWindow: ?int, maxOutputTokens: ?int}
     */
    public function capabilities(): array;
}
```

### `OpenAiCompatibleTransport` (abstract base — dimension A)

Carries the family commonality; **vendor branching inside the base is forbidden**:

- Request assembly (composes `Plugin\OpenAI\Client` — no duplicated auth/HTTP);
- Response parsing → `ChatResult` (unified; `reasoning_content` preserved);
- **Full retry semantics**; **error classification 620–624**;
- Protected slots (difference points):

```php
protected const BASE_URL = 'https://api.openai.com/v1';   // vendor subclasses override
protected const DEFAULT_MODEL = 'gpt-4o-mini';
protected const TIMEOUT = 60;
protected const RETRY_ON_STATUS = [429, 500, 502, 503, 504];

protected function defaultModel(): string                  // slot (capabilities / fallback)
protected function extraOptions(array $options): array     // sampling passthrough slot
protected function isParseable(Response $response): bool   // 624 decision slot
protected function modelFromEntry(object $entry): ?ModelInfo // catalog slot: filtering/normalization (null = filter out)
```

### Vendor classes (dimension B)

| Class | Base URL | Default model |
|---|---|---|
| `OpenAiTransport` | `https://api.openai.com/v1` | `gpt-4o-mini` |
| `DeepSeekTransport` | `https://api.deepseek.com/v1` | `deepseek-chat` |
| `QwenTransport` | `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen-turbo` |
| `KimiTransport` | `https://api.kimi.com/coding/v1` | `kimi-coding` |
| `MoonshotTransport` | `https://api.moonshot.cn/v1` | `moonshot-v1-8k` |
| `MiniMaxTransport` | `https://api.minimax.chat/v1` | `abab6.5s-chat` |
| `OrcaRouterTransport` | `https://api.orcarouter.ai/v1` | `deepseek/deepseek-v4-flash-free` (+ free-model catalog filter) |
| `GenericOpenAiTransport` | via config | via config (`default_model`) — escape hatch for less-known OpenAI-compatible services |

Most vendor classes are 3–5 lines (constant overrides). `OrcaRouterTransport` demonstrates the quirk slot: its `modelFromEntry()` implements the free-model filter and object-shaped pricing normalization observed in its real API.

### Retry semantics (complete, first-class)

```php
$options['retry'] = [
    'max'        => 0,                        // max retries; default 0 = off
    'delay_ms'   => 1000,                     // first backoff
    'multiplier' => 2.0,                      // exponential: delay_ms * multiplier^attempt
    'on_status'  => [429, 500, 502, 503, 504],// retryable statuses
];
// A Retry-After response header takes precedence over computed backoff.
// Retries exhausted → the last error goes through error classification.
```

### Error classification (620–624 transport sub-codes)

| Code | Meaning | Trigger |
|---|---|---|
| 620 | auth failure | HTTP 401/403 |
| 621 | rate limited | HTTP 429 (after retries exhausted) |
| 622 | server error | HTTP 5xx (after retries exhausted) |
| 623 | timeout | connection/execution timeout (cURL errno side) |
| 624 | unexpected response | non-retryable status, or 2xx with unparseable structure |

### `ChatResult` (unified response value object)

```php
public static function fromBody(?object $body): self;   // tolerant parsing — missing shapes → empty values
public function content(): string
public function reasoningContent(): ?string             // deepseek-r1-style thinking field
public function finishReason(): ?string                 // stop / length / tool_calls …
public function toolCalls(): array   // [{id, name, arguments, wire}] — wire kept verbatim for backfill
public function usage(): array
public function hasUsage(): bool
```

## Usage Examples

### Switching vendors = switching classes

```php
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Transport\{DeepSeekTransport, QwenTransport, GenericOpenAiTransport};

$agent = new Agent(new DeepSeekTransport(['api_key' => $key]), 'deepseek-chat');
// ... or any other vendor — nothing else changes:
$agent = new Agent(new QwenTransport(['api_key' => $key]), 'qwen-max');
$agent = new Agent(new GenericOpenAiTransport([
    'api_key'       => $key,
    'base_url'      => 'https://my-gateway.internal/v1',
    'default_model' => 'team-default',
]), 'team-default');
```

### Per-request model override + retry

```php
$result = $transport->chat(
    [['role' => 'user', 'content' => 'hi']],
    [
        'model'      => 'deepseek-reasoner',   // overrides vendor default for this call
        'max_tokens' => 512,
        'retry'      => ['max' => 2, 'delay_ms' => 500],
    ]
);
echo $result->content();
echo $result->usage()['total_tokens'];
```

### Custom vendor in 4 lines

```php
use Ws\Http\NanoGpt\Transport\OpenAiCompatibleTransport;

final class MyGatewayTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://gateway.mycompany.internal/v1';
    protected const DEFAULT_MODEL = 'corp-assistant';
}
```

### Model catalog (capabilities)

```php
$capabilities = $transport->capabilities();   // GET /models
foreach ($capabilities['models'] as $modelInfo) {
    echo $modelInfo->id(), ' — ', $modelInfo->name(), PHP_EOL;  // ModelInfo attribute model
}
```

## Configuration

Constructor config array (all vendor classes share the shape):

| Key | Required | Default | Meaning |
|---|---|---|---|
| `api_key` | yes | — | credential (Bearer) |
| `base_url` | no | class `BASE_URL` | endpoint override |
| `timeout` | no | `TIMEOUT` (60 s) | request timeout |
| `http` | no | new `Request` | **test injection seam** (`Request` instance) |
| `default_model` | Generic only | `gpt-4o-mini` | generic transport's fallback model |

## Dependencies

- **Depends on**: `Plugin\OpenAI\Client` (composition — the human-facing business client from the plugin layer), core `Request`/`Response`.
- **Depended on by**: `NanoGpt\Agent` (via the interface), `examples/cc-gpt` shell assembly (`settings.adapter` → vendor class).

Design details: [design/28-厂商接入层设计.md](../../design/28-厂商接入层设计.md).
