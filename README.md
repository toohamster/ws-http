# ws-http

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue) ![License](https://img.shields.io/badge/License-MIT-green) ![Tests](https://img.shields.io/badge/tests-615%20passing-brightgreen) ![Dependencies](https://img.shields.io/badge/dependencies-0-success)

[English](README.md) | [中文版本](README.zh-CN.md)

A lightweight cURL HTTP client library with an assertion layer, a scenario-based API testing engine, and a multi-provider agent orchestration layer — **zero external dependencies** (ext-curl / ext-json only).

## Four Layers, Use What You Need

| Layer | What you get | One-liner |
| --- | --- | --- |
| **core** | A cURL HTTP API tool: SSL / Basic·Digest auth / proxy / cookies / custom headers / JSON·form·multipart bodies | `(new Request())->get($url)` |
| **functional** | Assertion layer (`Watcher`), JSONPath expressions, scenario-based automation engine (variables / extraction / assertions / reports / CLI) | `Watcher::create($response)->assertStatusCode(200)` |
| **plugin** | Third-party service adapters (OpenAI-compatible protocol, WordPress), extensible registry | `(new OpenAI\Client($key))->chat()->create('hi')` |
| **NanoGpt** | Multi-provider agent orchestration: agent loop + tool protocol + sandbox + session/compaction/memory, connecting any vendor via `ChatTransportInterface` | `new Agent(new DeepSeekTransport([...]), 'model')` |

> Design principle: *"Simple things should be simple, complex things should be possible."* The simple path is always one line; advanced capabilities (custom operators, extension sources, plugin registration) appear only when you need them.

## Requirements

- PHP 7.4+ (`declare(strict_types=1)` throughout)
- ext-curl, ext-json

## Installation

```shell
composer require toohamster/ws-http
```

## Quick Start

### core: send a request

```php
use Ws\Http\Request;

$response = (new Request())->get('https://httpbin.org/get', [], ['name' => 'ws-http']);

$response->code;                  // 200
$response->body->args->name;      // JSON auto-decoded
$response->header('content-type'); // case-insensitive lookup

// When you need configuration (wither style — original instance untouched):
$request = (new Request())->withOptions(fn ($o) => $o
    ->withTimeout(5)
    ->withAuth('user', 'pass')
    ->withProxy('127.0.0.1', 8787));
```

Full core usage (POST/PUT/multipart files/cookies/error handling): [examples/httpbin-quick](examples/httpbin-quick/run.php) · manual: [docs/reference/core-http-client.md](docs/reference/core-http-client.md)

### functional: assertions + scenario automation

```php
use Ws\Http\Assert\Watcher;

// Chained assertions (first failure throws AssertionException)
Watcher::create($response)
    ->assertStatusCode(200)
    ->assertBody('IS_VALID_JSON')
    ->assertJsonPath('$.result', 'eq', 0);
```

Scenario files describe chained flows ("login → fetch token → place order") in JSON:

```jsonc
{
  "id": "smoke", "name": "Smoke test",
  "outputs": ["token"],                            // optional: declared → scenario is a data function
  "variables": [ { "name": "password", "value": "secret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "name": "Login", "url": "https://api.example.com/login",
      "method": "POST", "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" },
      "extract": [ { "var": "token", "source": "json", "path": "$.token" } ] },
    { "type": "http", "id": "me", "name": "Me", "url": "https://api.example.com/me",
      "method": "GET", "headers": { "Authorization": "Bearer ${token}" },
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
```

```shell
php bin/ws-http run scenario.json                 # text report; exit 1 on failure
php bin/ws-http run a.json b.json --format=json   # JSON report for CI
php bin/ws-http run scenario.json --var env=prod  # inject/override variables
```

Manual: [docs/reference/scenario-testing.md](docs/reference/scenario-testing.md) · runnable sample: [examples/scenario](examples/scenario/run.php)

### plugin: third-party services

```php
use Ws\Http\Plugin\OpenAI\Client;

// Any OpenAI-protocol-compatible service: change the base URL and connect (OpenAI/Qianfan/DeepSeek/vLLM…)
$client = new Client($apiKey, null, 'https://api.deepseek.com/v1');
$response = $client->chat()->create('hi', 'deepseek-chat');
$response->body->choices[0]->message->content;

// WordPress (Application Password)
$wp = new \Ws\Http\Plugin\WordPress\Client('https://blog.example.com', $appPassword, 'admin');
$wp->posts()->create('Title', 'Body');
```

Manual: [docs/reference/plugin-system.md](docs/reference/plugin-system.md) · runnable sample: [examples/openai-chat](examples/openai-chat/run.php)

### NanoGpt: multi-provider agent orchestration (`Ws\Http\NanoGpt`)

Agent loop (event-stream Generator) + tool protocol (`ToolInterface`/`ToolRegistry`) + sandbox + session management (truncation/snapshots/persistence/budget compaction/long-term memory). **Orchestration is vendor-agnostic**: transports are replaceable via `ChatTransportInterface` — adding a vendor = adding one transport class:

```php
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;   // 7 built-in vendor classes + Generic escape hatch

$agent = new Agent(new DeepSeekTransport(['api_key' => $apiKey]), 'deepseek-chat');
$agent->tools()->register(new \Ws\Http\NanoGpt\Tool\ReadFileTool($sandbox));

foreach ($agent->run('Read README.md and summarize it') as $event) {
    // text | tool_call | tool_result | error (transport failures carry 620–624 classification codes)
}
```

Manuals: [agent-core](docs/reference/agent-core.md) · [transports](docs/reference/agent-transports.md) · reference CLI: [examples/cc-gpt](examples/cc-gpt/README.md)

## Documentation

Full manual index (16 module references, bilingual): **[docs/README.md](docs/README.md)**

| Section | Contents |
| --- | --- |
| Core | HTTP client, configuration reference |
| Functional | assertions, expressions, scenarios, variables, cookies, datasets, outputs |
| NanoGpt | agent core, transports, session/memory/skills, cc-gpt shell |
| Reference | error codes (1xx–6xx), extension points |

Design documents (Chinese, developer-facing decision archive): [design/README.md](design/README.md) · progress ledger: [design/PROGRESS.md](design/PROGRESS.md)

## Extension Mechanism

| Extension point | How | Example |
| --- | --- | --- |
| Custom assertion operators | `Comparison::register('op', $impl)` or `FnComparator` closure | encryption checks, remote verification |
| Custom extraction sources | `VarExtractor::registerSource('xml', $impl)` | XML/HTML extraction |
| Third-party service adapters | implement `PluginInterface` + `PluginRegistry::register()` | new business-system clients |

Mechanism minimization: registry + interface — no container, no events, no auto-discovery. Manual: [docs/reference/extension-points.md](docs/reference/extension-points.md)

## Development

```shell
composer install
php74 vendor/bin/phpunit --testsuite Unit         # pure logic
php74 vendor/bin/phpunit --testsuite Engine       # engine orchestration (mock HTTP)
php74 vendor/bin/phpunit --testsuite Integration  # public httpbin (skippable)
php74 bin/ws-http run tests/fixtures/scenarios/booking-flow.json --format=text  # smoke
```

## 2.0 Migration Notes (from 1.x)

| Change | Notes |
| --- | --- |
| PHP 7.4+ / strict_types | PHP 5.x no longer supported; PECL http and `@file` legacy upload syntax removed |
| Default timeout 30s | 1.x had none (infinite wait) |
| SSL CA uses system store | bundled ca-bundle.crt removed; `withCaBundle()` to specify |
| User-Agent `ws-http/2.0` | 1.x was `ws-http/1.0` |
| `assertBody` containment fix | 1.x `strpos` args were reversed (assertion always failed); 2.0 semantics: passes when body contains expected |
| `assertHeaders` case-insensitive | 1.x header names were case-sensitive |
| Body auto Content-Type | `Body::json/form/multipart` return `PreparedBody` carrying the type (explicit headers win); PUT/DELETE string bodies get `text/plain` |
| `ARequest` static facade removed | unified `new Request()` |
| Diagnosable response object | `Response::$jsonError` records JSON decode failures; transport exceptions carry errno |
| Automated engine functional | 1.x was a parse skeleton; 2.0 implements extraction/assertions/reports/CLI |

## License

MIT
