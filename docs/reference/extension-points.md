[English](./extension-points.md) | [中文版本](./extension-points.zh-CN.md)

# Extension Points

> Cross-module: the four registries plus the request factory seam. One page for "how do I teach ws-http something new?"
> Design principle: **registry + interface — no containers, no events, no auto-discovery.** Extension cost = one interface implementation.

## Overview

ws-http exposes exactly four extension points (plus one injection seam). Each follows the same shape: a stable name, an interface, a static `register()` (or constructor injection), and a built-in default set that your addition coexists with.

| # | Extension point | Registry | Interface | Extends what |
|---|---|---|---|---|
| 1 | Assertion operators | `Ws\Http\Assert\Comparison` | `ComparatorInterface` / `FnComparator` | `assertJsonPath(..., $op, ...)` vocabulary |
| 2 | Extraction sources | `Ws\Http\Automated\VarExtractor` | `ExtractorInterface` | `extract[].source` vocabulary |
| 3 | Plugins | `Ws\Http\Plugin\PluginRegistry` | `PluginInterface` | auth providers, operators, sources, shared services in bulk |
| 4 | Pause value sources | `Ws\Http\Automated\Pause\PauseRegistry` | `ValueSource` | pause step `from` ids |
| — | HTTP transport | `Ws\Http\Automated\RequestFactoryInterface` | — | where/how HTTP is sent (mocking, decoration) |

## Key Components & Usage Examples

### 1. Custom assertion operator

```php
use Ws\Http\Assert\Comparison;
use Ws\Http\Contract\{ComparatorInterface, FnComparator};

// Full interface:
Comparison::register('within_range', new class implements ComparatorInterface {
    public function compare($actual, $expected): bool
    {
        [$min, $max] = $expected;
        return is_numeric($actual) && $actual >= $min && $actual <= $max;
    }
});

// Closure shorthand:
Comparison::register('is_uuid', new FnComparator(
    fn ($actual, $expected) => is_string($actual)
        && preg_match('/^[0-9a-f-]{36}$/', $actual) === 1
));

// Then use it anywhere operators are accepted:
Watcher::create($response)->assertJsonPath('$.latency_ms', 'within_range', [0, 500]);
```

### 2. Custom extraction source

```php
use Ws\Http\Automated\VarExtractor;
use Ws\Http\Contract\{ExtractorInterface, ExtractionOutcome};
use Ws\Http\Response;

final class XmlExtractor implements ExtractorInterface
{
    public function extract(Response $response, string $path): ExtractionOutcome
    {
        // parse $response->rawBody as XML, evaluate $path, return matched values
        // or a structured miss (the caller applies onMissing policy)
    }
}

VarExtractor::registerSource('xml', new XmlExtractor());
// Scenario usage:
// { "var": "title", "source": "xml", "path": "//title" }
```

### 3. Plugin (bulk contribution)

```php
use Ws\Http\Contract\{PluginInterface, PluginContext};

final class MyCompanyPlugin implements PluginInterface
{
    public function name(): string { return 'mycompany'; }

    public function register(PluginContext $ctx): void
    {
        $ctx->addAuth('corp-hmac', new CorpHmacAuthProvider());
        $ctx->addComparator('signature_valid', new SignatureComparator());
        $ctx->addExtractor('protobuf', new ProtobufExtractor());
        $ctx->share('corp-client', new CorpApiClient());
    }
}
```

See [plugin-system.md](plugin-system.md) for registration and built-ins.

### 4. Custom pause value source

```php
use Ws\Http\Automated\Pause\{PauseRegistry, ValueSource, ValueResult};

final class VaultSource implements ValueSource
{
    public function fetch(string $var, array $options): ValueResult
    {
        $secret = $this->vault->read((string) ($options['path'] ?? ''));
        return ValueResult::of($secret);   // or a structured failure
    }
}

$registry->register(new VaultSource());
// Scenario usage:
// { "type": "pause", "var": "dbPassword", "from": "vault", "options": { "path": "secret/db" } }
```

### 5. HTTP transport seam (testing / decoration)

```php
use Ws\Http\Contract\RequestFactoryInterface;
use Ws\Http\{Request, RequestOptions};

final class LoggingRequestFactory implements RequestFactoryInterface
{
    public function create(RequestOptions $options): Request
    {
        return (new Request($options))->withOptions(fn ($o) => $o
            ->withCurlOpt(CURLOPT_VERBOSE, true));
    }
}

$runner = new Runner(new LoggingRequestFactory());   // every scenario HTTP call now logs
```

Built-ins: `RequestFactory` (real HTTP) and `StaticRequestFactory` (reuses one injected instance — used by tool integration).

## Notes & Guarantees

- **Built-ins are defaults, not locks** — registered entries coexist with them; name collisions → exception (501/603-style), never silent overwrite.
- **No cross-cutting magic** — a plugin cannot change engine behavior, only add vocabulary and clients. This keeps the kernel predictable.
- **Error codes for extension failures** live in their owning segments (see [error-codes.md](error-codes.md)).

## Dependencies

All registries depend only on their own contract interfaces (in `Ws\Http\Contract`). None of them load configuration files or reach the network — wiring is always explicit, in composition code (your bootstrap or a plugin's `register()`).
