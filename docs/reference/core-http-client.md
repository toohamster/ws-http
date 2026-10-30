[English](./core-http-client.md) | [中文版本](./zh-CN/core-http-client.zh-CN.md)

# Core — HTTP Client

> Module: `Ws\Http\Request`, `Ws\Http\RequestOptions`, `Ws\Http\Response`, `Ws\Http\HeaderBag`, `Ws\Http\Body`, `Ws\Http\PreparedBody`, `Ws\Http\Method`, `Ws\Http\RequestException`
> Namespace: `Ws\Http` · PHP 7.4+, `strict_types`, zero external dependencies (ext-curl, ext-json only)

## Overview

The HTTP client is the foundation of ws-http. It wraps PHP's cURL extension behind a small, immutable-configuration API:

- **`Request`** executes HTTP calls through a single choke point (`send()`) and returns a unified `Response`.
- **`RequestOptions`** is a *wither-style* value object: every configuration change returns a new instance, never mutating the original. This makes request configuration safe to share and derive.
- **`Response`** unifies status, headers, body, raw body and cURL info, with automatic JSON decoding.
- **`Body`** builds request payloads (JSON / form / multipart / raw) that self-describe their Content-Type via `PreparedBody`.

Design principle: *simple things should be simple* — one line for a GET; *complex things should be possible* — SSL, Basic/Digest auth, proxies, cookies, custom headers, raw cURL option passthrough.

## Key Components

### `Request`

The execution engine. All shortcut methods delegate to `send()`, the single choke point for every request.

```php
public function __construct(?RequestOptions $options = null)
public function withOptions(callable $mutator): self   // (RequestOptions): RequestOptions — derived copy, original untouched
public function options(): RequestOptions
public function get(string $url, array $headers = [], $parameters = null): Response
public function head(string $url, array $headers = [], $parameters = null): Response
public function options_(string $url, array $headers = [], $parameters = null): Response  // named options_ to avoid clash with withOptions()
public function post(string $url, array $headers = [], $body = null): Response
public function put(string $url, array $headers = [], $body = null): Response
public function patch(string $url, array $headers = [], $body = null): Response
public function delete(string $url, array $headers = [], $body = null): Response
public function trace(string $url, array $headers = [], $body = null): Response
public function send(string $method, string $url, $body = null, array $headers = []): Response
```

Behavior notes:

- For body-less methods (GET/HEAD/OPTIONS per `Method::NO_BODY_METHODS`), array/object parameters are flattened into the query string (recursive `a[b]` keys, CURLFile-aware).
- For body methods, `PreparedBody` content is extracted and its Content-Type applied (explicit user headers win). String bodies on PUT/PATCH/DELETE get `text/plain` automatically if none set (some servers reject untyped bodies).
- Header names are lowercased on send; `user-agent: ws-http/2.0` and an empty `Expect:` are injected unless overridden.
- Transport failures (curl errno ≠ 0) throw `RequestException` carrying `errno`, `error`, `method`, `url`.

### `Response`

```php
public $code;        // int — HTTP status
public $statusLine;  // string — e.g. "HTTP/1.1 200 OK"
public $headers;     // HeaderBag — case-insensitive lookups
public $rawBody;     // string — undecoded body
public $curlInfo;    // array — full curl_getinfo() output
public $body;        // mixed — decoded JSON when Content-Type is application/json and decode succeeds; false otherwise
public $jsonError;   // ?array{0: int, 1: string} — [errno, message] when JSON decode failed
public function isOk(): bool        // 200–299
public function header(string $name): ?string  // case-insensitive
```

`$body` stays `false` for non-JSON responses and failed decodes — check `$jsonError` to distinguish.

### `Body` (static factory)

```php
Body::json($data, int $options = 0, int $depth = 512): PreparedBody   // Content-Type: application/json
Body::form($data): PreparedBody                                       // application/x-www-form-urlencoded
Body::multipart(array $data, array $files = []): PreparedBody
Body::file(string $filename, string $mimeType = '', string $postName = ''): \CURLFile
Body::raw(string $data, ?string $contentType = null): PreparedBody
```

All return `PreparedBody` (content + suggested Content-Type; `__toString()` renders the content for diagnostics).

## Usage Examples

### One-line GET

```php
use Ws\Http\Request;

$response = (new Request())->get('https://httpbin.org/get', [], ['name' => 'ws-http']);

echo $response->code;                 // 200
echo $response->body->args->name;     // "ws-http" (JSON auto-decoded)
echo $response->header('content-type'); // case-insensitive
```

### Configured request (wither style)

```php
use Ws\Http\Request;
use Ws\Http\Body;

$request = (new Request())->withOptions(function ($o) {
    return $o->withTimeout(5)
             ->withAuth('user', 'pass')
             ->withProxy('127.0.0.1', 8787);
});

$response = $request->post(
    'https://httpbin.org/post',
    [],                                    // extra headers (optional)
    Body::json(['title' => 'Hello', 'tags' => ['a', 'b']])
);

$response->body->json->title;              // "Hello"
```

### Deriving without mutation

```php
$base = new Request();
$authed = $base->withOptions(fn ($o) => $o->withAuth('u', 'p'));

// $base is untouched; $authed sends with an Authorization header.
```

### Multipart file upload

```php
$response = (new Request())->post('https://httpbin.org/post', [], Body::multipart(
    ['field' => 'value'],
    ['upload' => Body::file('/tmp/report.pdf', 'application/pdf', 'report.pdf')]
));
```

### Handling transport errors

```php
use Ws\Http\RequestException;

try {
    (new Request())->get('https://unreachable.invalid/');
} catch (RequestException $e) {
    echo $e->getCode();     // curl errno (e.g. 6 = couldn't resolve host)
    echo $e->getMessage();  // human-readable curl error
}
```

## Configuration

- **Default timeout**: 30 s (v1 had none — infinite waits).
- **Default UA**: `ws-http/2.0`.
- **SSL**: system certificate store by default; pass `->withCaBundle('/path/to/ca.pem')` for custom bundles.
- **JSON decode**: objects by default; `withJsonOpts(true)` for associative arrays.
- Full per-option reference: see [core-configuration.md](core-configuration.md).

## Dependencies

- **Depends on**: PHP ext-curl, ext-json; `Ws\Http\Method` / `MethodHelper` (method semantics), `Ws\Http\UrlKit` / `StrKit` (URL encoding, string utilities).
- **Depended on by**: every upper layer — `Assert\Watcher` (assertions over `Response`), `Automated\Runner` (scenario engine), `Plugin\*` clients, `NanoGpt\Transport\*` (agent transports compose it via `Plugin\OpenAI\Client`).

Runnable sample: [examples/httpbin-quick/run.php](../../examples/httpbin-quick/run.php).

Design details: [design/11-HTTP客户端设计.md](../../design/11-HTTP客户端设计.md).
