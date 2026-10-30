[English](../core-configuration.md) | [中文版本](./core-configuration.zh-CN.md)

# Core — 配置参考

> 模块:`Ws\Http\RequestOptions`(wither 方法全量参考)
> 配套阅读:[core-http-client.zh-CN.md](./core-http-client.zh-CN.md)

## Overview

`RequestOptions` 把所有 cURL 层面的配置旋钮收拢在类型化的 wither 方法之后。实例不可变:每个 `with*()` 返回**新对象**。这带来三个保证:

1. 配置好的 options 对象可跨请求复用,不会互相污染;
2. `Request::withOptions(callable)` 派生出一个配置好的 `Request`,原实例保持不动;
3. 默认值集中在一处(构造器)可查,不散落各处。

## Key Components

### 默认值(构造器)

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `timeout` | `30` 秒 | v1 无超时(无限等待)——2.0 有意的行为变更 |
| `jsonOpts` | `[false, 512, 0]` | 传给 `json_decode()`:对象形态、深度 512、无标志位 |
| `defaultHeaders` | `User-Agent: ws-http/2.0`、空 `Expect:` | 规避 cURL 的 1 秒 Expect/100-continue 延迟 |
| `verifyPeer` / `verifyHost` | `true` / `true` | 系统 CA 库 |
| `maxRedirects` | `10` | |
| 其余 | 未设 | 认证、代理、Cookie、curlOpts 按需启用 |

### 方法参考

```php
// 超时
public function withTimeout(int $seconds): self
public function withTimeoutMs(int $milliseconds): self
public function timeout(): int          // 秒(兜底 30)
public function timeoutMs(): ?int       // 仅经 withTimeoutMs() 设置后非 null

// TLS
public function withVerifyPeer(bool $enabled): self     // CURLOPT_SSL_VERIFYPEER
public function withVerifyHost(bool $enabled): self     // CURLOPT_SSL_VERIFYHOST
public function withCaBundle(string $path): self        // CURLOPT_CAINFO

// 响应 JSON 解码
public function withJsonOpts(bool $assoc, int $depth = 512, int $options = 0): self

// 请求头(与单请求头合并;单请求优先)
public function withDefaultHeaders(array $headers): self
public function withDefaultHeader(string $name, string $value): self
public function withoutDefaultHeaders(): self
public function defaultHeaders(): HeaderBag

// Cookie
public function withCookie(string $cookieString): self  // 如 "k=v; k2=v2"(CURLOPT_COOKIE)
public function withCookieFile(string $path): self      // Netscape jar(CURLOPT_COOKIEFILE)
public function cookie(): ?string
public function cookieFile(): ?string

// 认证
public function withAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self
public function auth(): ?array                          // ['user','pass','method']

// 代理
public function withProxy(string $address, int $port, int $type = CURLPROXY_HTTP, bool $tunnel = false): self
public function withProxyAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self
public function proxy(): ?array

// cURL 原生透传(最高优先级)
public function withCurlOpts(array $opts): self          // [CURLOPT_X => value, ...]
public function withCurlOpt(int $option, $value): self
public function withoutCurlOpts(): self
public function curlOpts(): array

// 重定向
public function withMaxRedirects(int $count): self
public function maxRedirects(): int
```

## Usage Examples

### 超时 + TLS 加固 + 自定义 CA

```php
use Ws\Http\Request;

$request = (new Request())->withOptions(fn ($o) => $o
    ->withTimeout(10)
    ->withVerifyPeer(true)
    ->withVerifyHost(true)
    ->withCaBundle('/etc/ssl/internal-ca.pem'));

$response = $request->get('https://internal.example.com/api');
```

### 经默认头做 Bearer 认证(plugin 风格)

```php
$authed = (new Request())->withOptions(fn ($o) => $o
    ->withDefaultHeader('Authorization', 'Bearer ' . $token));
```

### Digest 认证 + SOCKS5 代理

```php
$hardened = (new Request())->withOptions(fn ($o) => $o
    ->withAuth('user', 'pass', CURLAUTH_DIGEST)
    ->withProxy('socks5.internal', 1080, CURLPROXY_SOCKS5));
```

### cURL 原生选项透传(覆盖一切)

```php
// 强制 HTTP/2——用户 curlOpts 覆盖所有内部组装项:
$tuned = (new Request())->withOptions(fn ($o) => $o
    ->withCurlOpts([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]));
```

### Cookie jar 复用

```php
$session = (new Request())->withOptions(fn ($o) => $o
    ->withCookieFile('/tmp/session.jar'));   // Netscape 格式;cURL 原地更新

$session->get('https://httpbin.org/cookies/set/k/v');
$session->get('https://httpbin.org/cookies'); // 回发 k=v
```

## Configuration

优先级链(从高到低):

1. 用户 `curlOpts`(`withCurlOpts` / `withCurlOpt`);
2. 单请求显式头(`$request->get($url, $headers)`);
3. `RequestOptions` 的 `defaultHeaders`;
4. 内部组装项(URL、体、UA、Expect、超时、SSL…)。

## Dependencies

- **依赖**:除 ext-curl 常量外无依赖(纯值对象)。
- **被依赖**:`Request`(消费方)、`Automated\Runner`(场景 settings 翻译为它)、`Plugin\*` 客户端(认证提供器产出封好的 options)、`Ws\Http\Contract\RequestFactoryInterface` 的实现者。

设计详情:[design/11-HTTP客户端设计.md](../../../design/11-HTTP客户端设计.md)。
