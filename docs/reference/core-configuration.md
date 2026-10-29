[English](./core-configuration.md) | [中文版本](./core-configuration.zh-CN.md)

# Core — Configuration Reference

> Module: `Ws\Http\RequestOptions` (full wither reference)
> Companion to [core-http-client.md](core-http-client.md)

## Overview

`RequestOptions` centralizes every cURL-level knob behind typed wither methods. Instances are immutable: each `with*()` returns a **new** object. This gives three guarantees:

1. A configured options object can be reused across requests without cross-contamination.
2. `Request::withOptions(callable)` derives a configured `Request` while the original stays untouched.
3. Defaults are discoverable in one place (constructor), not scattered.

## Key Components

### Defaults (constructor)

| Setting | Default | Notes |
|---|---|---|
| `timeout` | `30` seconds | v1 had no timeout (infinite wait) — deliberate 2.0 behavior change |
| `jsonOpts` | `[false, 512, 0]` | passed to `json_decode()`: objects, depth 512, no flags |
| `defaultHeaders` | `User-Agent: ws-http/2.0`, `Expect:` (empty) | avoids cURL's 1s Expect/100-continue delay |
| `verifyPeer` / `verifyHost` | `true` / `true` | system CA store |
| `maxRedirects` | `10` | |
| everything else | absent | auth, proxy, cookies, curlOpts opt-in |

### Method reference

```php
// Timeouts
public function withTimeout(int $seconds): self
public function withTimeoutMs(int $milliseconds): self
public function timeout(): int          // seconds (fallback 30)
public function timeoutMs(): ?int       // null unless set via withTimeoutMs()

// TLS
public function withVerifyPeer(bool $enabled): self     // CURLOPT_SSL_VERIFYPEER
public function withVerifyHost(bool $enabled): self     // CURLOPT_SSL_VERIFYHOST
public function withCaBundle(string $path): self        // CURLOPT_CAINFO

// JSON decoding of responses
public function withJsonOpts(bool $assoc, int $depth = 512, int $options = 0): self

// Headers (merged with per-request headers; per-request wins)
public function withDefaultHeaders(array $headers): self
public function withDefaultHeader(string $name, string $value): self
public function withoutDefaultHeaders(): self
public function defaultHeaders(): HeaderBag

// Cookies
public function withCookie(string $cookieString): self  // e.g. "k=v; k2=v2" (CURLOPT_COOKIE)
public function withCookieFile(string $path): self      // Netscape jar (CURLOPT_COOKIEFILE)
public function cookie(): ?string
public function cookieFile(): ?string

// Authentication
public function withAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self
public function auth(): ?array                          // ['user','pass','method']

// Proxy
public function withProxy(string $address, int $port, int $type = CURLPROXY_HTTP, bool $tunnel = false): self
public function withProxyAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self
public function proxy(): ?array

// Raw cURL passthrough (highest precedence)
public function withCurlOpts(array $opts): self          // [CURLOPT_X => value, ...]
public function withCurlOpt(int $option, $value): self
public function withoutCurlOpts(): self
public function curlOpts(): array

// Redirects
public function withMaxRedirects(int $count): self
public function maxRedirects(): int
```

## Usage Examples

### Timeout, TLS hardening, custom CA

```php
use Ws\Http\Request;

$request = (new Request())->withOptions(fn ($o) => $o
    ->withTimeout(10)
    ->withVerifyPeer(true)
    ->withVerifyHost(true)
    ->withCaBundle('/etc/ssl/internal-ca.pem'));

$response = $request->get('https://internal.example.com/api');
```

### Bearer auth via default headers (plugin-style)

```php
$authed = (new Request())->withOptions(fn ($o) => $o
    ->withDefaultHeader('Authorization', 'Bearer ' . $token));
```

### Digest auth + SOCKS5 proxy

```php
$hardened = (new Request())->withOptions(fn ($o) => $o
    ->withAuth('user', 'pass', CURLAUTH_DIGEST)
    ->withProxy('socks5.internal', 1080, CURLPROXY_SOCKS5));
```

### Raw cURL option passthrough (wins over everything)

```php
// Force HTTP/2 — user curlOpts override all assembled options:
$tuned = (new Request())->withOptions(fn ($o) => $o
    ->withCurlOpts([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]));
```

### Cookie jar reuse

```php
$session = (new Request())->withOptions(fn ($o) => $o
    ->withCookieFile('/tmp/session.jar'));   // Netscape format; cURL updates it in place

$session->get('https://httpbin.org/cookies/set/k/v');
$session->get('https://httpbin.org/cookies'); // sends k=v back
```

## Configuration

Precedence chain (highest first):

1. User `curlOpts` (`withCurlOpts` / `withCurlOpt`);
2. Per-request explicit headers (`$request->get($url, $headers)`);
3. `defaultHeaders` from `RequestOptions`;
4. Internally assembled options (URL, body, UA, Expect, timeout, SSL…).

## Dependencies

- **Depends on**: nothing beyond ext-curl constants (pure value object).
- **Depended on by**: `Request` (consumer), `Automated\Runner` (scenario settings are translated into it), `Plugin\*` clients (auth providers produce sealed options), `Ws\Http\Contract\RequestFactoryInterface` implementors.

Design details: [design/11-HTTP客户端设计.md](../../design/11-HTTP客户端设计.md).
