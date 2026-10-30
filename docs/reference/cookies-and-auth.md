[English](./cookies-and-auth.md) | [中文版本](./zh-CN/cookies-and-auth.zh-CN.md)

# Cookies & External Credentials

> Module: `Ws\Http\Automated` — `MemoryCookieStore`, `FileCookieStore`, `Pause\PauseRegistry`, `Pause\StdinSource`, `Pause\EnvironmentSource`, `Pause\PollSource`, `Pause\ValueSource`, `Pause\ValueResult`; contract: `Ws\Http\Contract\CookieStoreInterface`

## Overview

Two orthogonal facilities that statefully support realistic API flows:

1. **Cookie stores** — transport-layer cookie jars for scenarios (`settings.cookieStore`). The jar follows FILE+JAR semantics: collect from every response, replay on matching requests, prune expired entries.
2. **External credential injection** — the `pause` step type. A scenario can stop mid-flow and pull a value from outside (human input, environment, polling an endpoint) instead of hardcoding credentials. *Pause/resume is a flow dimension; the value source is a data dimension* — one step type, pluggable strategies.

## Key Components

### Cookie stores (`settings.cookieStore`)

| Setting | Implementation | Behavior |
|---|---|---|
| `memory` (default) | `MemoryCookieStore` | In-process jar; host-exact matching; expired entries pruned on read; **no domain-suffix matching** (deliberate, scenario-scope simplicity) |
| `none` | anonymous empty impl | No cookies sent or collected |
| `file:<path>` | `FileCookieStore` | memory semantics + persistence: loads a Netscape-format cookie file at construction (if present), writes it back at scenario end (`Runner::run` calls `persist()`); corrupted lines tolerated; write failure throws AutomatedException 403 |

```php
interface CookieStoreInterface {
    /** Cookie header value to inject for this URL, or null. */
    public function cookieHeaderFor(string $url): ?string;
    /** Collect Set-Cookie headers from the response (called for any status code). */
    public function collectFrom(Response $response, string $url): void;
}
```

Collection happens after every request regardless of status — 4xx responses may still set valid cookies.

### Pause step & value sources

Step shape (V12–V13 validated):

```jsonc
{
  "type": "pause", "id": "approve", "name": "Manual approval",
  "var": "approvalCode",          // required — variable to receive the value
  "from": "stdin",                // required — registered ValueSource id
  "options": { "message": "Enter approval code" },   // source-specific, scalar map
  "extract": [ ... ]              // optional — post-process raw value (e.g. template)
}
```

Built-in sources (`from` ids):

| id | Class | Behavior |
|---|---|---|
| `stdin` | `Pause\StdinSource` | Interactive terminal prompt (`options.message`, `options.mask`) |
| `environment` | `Pause\EnvironmentSource` | Read env var (`options.env`) or file (`options.file`) |
| `poll` | `Pause\PollSource` | GET an endpoint until an extraction matches (`options.interval`, `options.timeout`, `options.path`, `options.response`) — time parameters belong to poll only |

```php
final class PauseRegistry
{
    public function __construct(array $sources = [], array $pollExtractors = [], array $pollDefaults = [])
    public function register(ValueSource $source): void
    public function has(string $id): bool
    public function get(string $id): ValueSource      // throws AutomatedException 407 on unknown id
    public function ids(): array
}

interface ValueSource {
    /** @return ValueResult (value or structured failure) */
    public function fetch(string $var, array $options): ValueResult;
}
```

Unknown `from` references are validated at Runner assembly time (407), not by the parser — the registry is not a parser concern.

## Usage Examples

### Cookie jar across a login flow

```jsonc
{
  "id": "cookie-flow", "name": "Cookie flow",
  "settings": { "cookieStore": "memory" },
  "steps": [
    { "type": "http", "id": "login", "name": "Login",
      "url": "https://api.example.com/login", "method": "POST",
      "body": { "mode": "json", "content": "{\"u\":\"a\",\"p\":\"b\"}" } },
    { "type": "http", "id": "me", "name": "Me",
      "url": "https://api.example.com/me", "method": "GET",
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
// Session cookie set by "login" is replayed automatically on "me".
```

### Persistent cookies across runs

```jsonc
{ "settings": { "cookieStore": "file:.runtime/cookies.jar" }, "...": "..." }
// First run: jar starts empty (or from existing file), saved back at end.
// Next run: picks up where the last one left off — handy for authenticated session reuse.
```

### Human-in-the-loop credential

```jsonc
{
  "type": "pause", "id": "otp", "name": "One-time code",
  "var": "otp", "from": "stdin",
  "options": { "message": "Enter the OTP from your authenticator" }
},
{ "type": "http", "id": "verify", "url": "https://api.example.com/verify?code=${otp}", "method": "GET" }
```

### Wait for an async job to finish

```jsonc
{
  "type": "pause", "id": "wait-job", "name": "Wait for job",
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

- Cookie behavior: scenario `settings.cookieStore` only.
- Pause behavior: per-step `options` (validated by V13 as a scalar map / scalar list).
- Error codes: `406–408` pause value failures (4xx engine segment); `407` unknown source.

## Dependencies

- **Depends on**: core `Response`/`HeaderBag` (cookie collection), `Ws\Http\Expression` (poll extraction).
- **Depended on by**: `Automated\Runner` (store creation per run; pause step execution), scenario authors (settings/steps).

Design details: [design/16-执行器设计.md](../../design/16-执行器设计.md) (cookies), [design/22-外部凭据注入设计.md](../../design/22-外部凭据注入设计.md) (pause).
