[English](./getting-started.md) | [中文版本](./zh-CN/getting-started.zh-CN.md)

# Getting Started — First 5 Minutes

> A continuous path from `composer install` to your first working scenario and agent. Every snippet uses real signatures (verified against `src/`).

## 0. Install

```shell
composer require toohamster/ws-http
# Requirements: PHP 7.4+, ext-curl, ext-json. No other dependencies.
```

## 1. core — your first request (30 seconds)

```php
use Ws\Http\Request;

$response = (new Request())->get('https://httpbin.org/get', [], ['name' => 'ws-http']);

echo $response->code;              // 200
echo $response->body->args->name;  // "ws-http" (JSON auto-decoded)
```

If this fails with a `RequestException`, the code **is** the curl errno (6 = DNS, 7 = connection refused, 28 = timeout) — see [error-codes.md](error-codes.md).

## 2. functional — assert it (1 minute)

```php
use Ws\Http\Assert\Watcher;

Watcher::create($response)
    ->assertStatusCode(200)
    ->assertJsonPath('$.args.name', 'eq', 'ws-http');
// First failure throws AssertionException (3xx codes). Collect mode: see assertion.md.
```

## 3. functional — your first scenario (2 minutes)

Save `smoke.json`:

```jsonc
{
  "id": "smoke", "name": "Smoke",
  "variables": [ { "name": "password", "value": "secret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "name": "Login", "url": "https://httpbin.org/post",
      "method": "POST", "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" },
      "extract": [ { "var": "token", "source": "json", "path": "$.headers.Accept" } ] },
    { "type": "http", "id": "check", "name": "Check", "url": "https://httpbin.org/status/200",
      "method": "GET",
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
```

```shell
php bin/ws-http run smoke.json    # inside the repo checkout (bin/ws-http; no vendor bin is published)
```

Exit codes: 0 all passed · 1 failed steps · 2 parse error. Full manual: [scenario-testing.md](scenario-testing.md).

## 4. NanoGpt — your first agent (2 minutes)

```php
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;   // or any vendor class / GenericOpenAiTransport

$agent = new Agent(new DeepSeekTransport(['api_key' => getenv('DEEPSEEK_API_KEY')]), 'deepseek-chat');

foreach ($agent->run('Reply with exactly one word: pong') as $event) {
    if ($event['type'] === 'text') {
        echo $event['text'], PHP_EOL;   // "pong"
    }
}

echo $agent->conversation()->usage()['total_tokens'], " tokens\n";
```

Add a tool and watch the loop:

```php
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Tool\WriteFileTool;

$agent->tools()->register(new WriteFileTool(new Sandbox('/tmp/ws-demo')));

foreach ($agent->run('Create hello.txt containing "hi" using the write_file tool') as $event) {
    // tool_call → tool_result → text
}
```

Full manual: [agent-core.md](agent-core.md) · [agent-transports.md](agent-transports.md) · reference CLI: [agent-ccgpt-shell.md](agent-ccgpt-shell.md)

## Troubleshooting (most common first contact errors)

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `RequestException` code 6 | Domain doesn't resolve | Check URL; behind a proxy? `withProxy()` |
| `RequestException` code 28 | Timeout (default 30 s) | `withTimeout()` — slow endpoint or network |
| `$response->body === false` | Not JSON / decode failed | Inspect `$response->jsonError`; `rawBody` has the raw text |
| Scenario exits 2 | Parse error (V1–V19) | Message carries a JSON Pointer — fix that field; invalid JSONPath → V9 |
| Scenario 405 extraction failed | `onMissing=fail` and path matched nothing | Verify path against the real response; or `onMissing: "skip"/"default"` |
| Agent error code 620 | Auth rejected (401/403) | Wrong/missing `api_key`; expired token |
| Agent error code 621 | Rate limited (429) | Back off; add `retry: ['max' => 2]`; or /compact to shrink context |
| Agent error code 624 | Unexpected response | 2xx but unparseable — check `base_url` points to an OpenAI-compatible endpoint |
| Agent error code 610 | Sandbox escape | Tool path outside the sandbox root — use paths inside it |
| Tools never called | Model sees no tools or can't use them | Tools registered? Schema accurate? Model supports function calling? (see the mechanics guide in `docs/`) |

## Where to go next

- Core details: [core-http-client.md](core-http-client.md) · [core-configuration.md](core-configuration.md)
- Testing depth: [scenario-testing.md](scenario-testing.md) · [data-driven-testing.md](data-driven-testing.md) · [scenario-outputs.md](scenario-outputs.md)
- Agent depth: [agent-transports.md](agent-transports.md) · [agent-session.md](agent-session.md)
- Extending: [extension-points.md](extension-points.md) · full index: [docs/README.md](../README.md)
