[English](../extension-points.md) | [中文版本](./extension-points.zh-CN.md)

# 扩展点

> 跨模块:四个注册表 + 请求工厂缝隙。一页回答"怎么教会 ws-http 新东西"。
> 设计原则:**注册表 + 接口——无容器、无事件、无自动发现。** 扩展成本 = 一个接口实现。

## Overview

ws-http 恰好暴露四个扩展点(外加一个注入缝隙)。每个都同构:一个稳定名、一个接口、一个静态 `register()`(或构造注入)、一套与你的扩展共存的内建默认集。

| # | 扩展点 | 注册表 | 接口 | 扩展什么 |
|---|---|---|---|---|
| 1 | 断言操作符 | `Ws\Http\Assert\Comparison` | `ComparatorInterface` / `FnComparator` | `assertJsonPath(..., $op, ...)` 词汇表 |
| 2 | 提取 source | `Ws\Http\Automated\VarExtractor` | `ExtractorInterface` | `extract[].source` 词汇表 |
| 3 | 插件 | `Ws\Http\Plugin\PluginRegistry` | `PluginInterface` | 批量贡献认证器/操作符/source/共享服务 |
| 4 | pause 取值来源 | `Ws\Http\Automated\Pause\PauseRegistry` | `ValueSource` | pause 步骤 `from` id |
| — | HTTP 传输 | `Ws\Http\Automated\RequestFactoryInterface` | — | HTTP 从哪发/怎么发(替身、装饰) |

## Key Components & Usage Examples

### 1. 自定义断言操作符

```php
use Ws\Http\Assert\Comparison;
use Ws\Http\Contract\{ComparatorInterface, FnComparator};

// 完整接口:
Comparison::register('within_range', new class implements ComparatorInterface {
    public function compare($actual, $expected): bool
    {
        [$min, $max] = $expected;
        return is_numeric($actual) && $actual >= $min && $actual <= $max;
    }
});

// 闭包简写:
Comparison::register('is_uuid', new FnComparator(
    fn ($actual, $expected) => is_string($actual)
        && preg_match('/^[0-9a-f-]{36}$/', $actual) === 1
));

// 之后在接受操作符的任何地方使用:
Watcher::create($response)->assertJsonPath('$.latency_ms', 'within_range', [0, 500]);
```

### 2. 自定义提取 source

```php
use Ws\Http\Automated\VarExtractor;
use Ws\Http\Contract\{ExtractorInterface, ExtractionOutcome};
use Ws\Http\Response;

final class XmlExtractor implements ExtractorInterface
{
    public function extract(Response $response, string $path): ExtractionOutcome
    {
        // 把 $response->rawBody 按 XML 解析,求值 $path,返回匹配值
        // 或结构化未命中(调用方套用 onMissing 策略)
    }
}

VarExtractor::registerSource('xml', new XmlExtractor());
// 场景用法:
// { "var": "title", "source": "xml", "path": "//title" }
```

### 3. 插件(批量贡献)

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

见 [plugin-system.zh-CN.md](./plugin-system.zh-CN.md)(注册与内建)。

### 4. 自定义 pause 取值来源

```php
use Ws\Http\Automated\Pause\{PauseRegistry, ValueSource, ValueResult};

final class VaultSource implements ValueSource
{
    public function fetch(string $var, array $options): ValueResult
    {
        $secret = $this->vault->read((string) ($options['path'] ?? ''));
        return ValueResult::of($secret);   // 或结构化失败
    }
}

$registry->register(new VaultSource());
// 场景用法:
// { "type": "pause", "var": "dbPassword", "from": "vault", "options": { "path": "secret/db" } }
```

### 5. HTTP 传输缝隙(测试 / 装饰)

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

$runner = new Runner(new LoggingRequestFactory());   // 场景的每个 HTTP 调用现在都打日志
```

内建:`RequestFactory`(真实 HTTP)与 `StaticRequestFactory`(复用一个注入实例——工具集成用)。

## 说明与保证

- **内建是默认,不是锁**——注册项与内建共存;重名 → 异常(501/603 类),绝不静默覆盖;
- **无横切魔法**——插件不能改变引擎行为,只能增加词汇与客户端。这让内核保持可预测;
- 扩展失败的错误码归各自段(见 [error-codes.zh-CN.md](./error-codes.zh-CN.md))。

## Dependencies

所有注册表只依赖自己的契约接口(在 `Ws\Http\Contract`)。它们都不加载配置文件、不触网——装配永远显式,发生在组合代码里(你的 bootstrap 或插件的 `register()`)。
