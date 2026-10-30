[English](../core-http-client.md) | [中文版本](./core-http-client.zh-CN.md)

# Core — HTTP 客户端

> 模块:`Ws\Http\Request`、`Ws\Http\RequestOptions`、`Ws\Http\Response`、`Ws\Http\HeaderBag`、`Ws\Http\Body`、`Ws\Http\PreparedBody`、`Ws\Http\Method`、`Ws\Http\RequestException`
> 命名空间:`Ws\Http` · PHP 7.4+、`strict_types`、零外部依赖(仅 ext-curl / ext-json)

## Overview

HTTP 客户端是 ws-http 的地基。它把 PHP 的 cURL 扩展封装在一套小而不可变配置的 API 之后:

- **`Request`** 是执行引擎——所有快捷方法最终都走 `send()` 这个唯一收口,返回统一的 `Response`;
- **`RequestOptions`** 是 *wither 风格*(不可变派生)的值对象:每次配置变更返回新实例,绝不修改原对象——配置可以安全共享与派生;
- **`Response`** 统一承载状态码、响应头、解析体、原始体与 cURL 信息,JSON 自动解码;
- **`Body`** 构造请求载荷(JSON / 表单 / Multipart / 原始),经 `PreparedBody` 自带 Content-Type。

设计原则:*简单的事必须简单*——GET 一行搞定;*复杂的事必须可能*——SSL、Basic/Digest 认证、代理、Cookie、自定义头、cURL 原生选项透传。

## Key Components

### `Request`

执行引擎。所有快捷方法都委托给 `send()`——所有请求的唯一收口。

```php
public function __construct(?RequestOptions $options = null)
public function withOptions(callable $mutator): self   // (RequestOptions): RequestOptions — 派生副本,原对象不动
public function options(): RequestOptions
public function get(string $url, array $headers = [], $parameters = null): Response
public function head(string $url, array $headers = [], $parameters = null): Response
public function options_(string $url, array $headers = [], $parameters = null): Response  // 命名为 options_ 以避开 withOptions()
public function post(string $url, array $headers = [], $body = null): Response
public function put(string $url, array $headers = [], $body = null): Response
public function patch(string $url, array $headers = [], $body = null): Response
public function delete(string $url, array $headers = [], $body = null): Response
public function trace(string $url, array $headers = [], $body = null): Response
public function send(string $method, string $url, $body = null, array $headers = []): Response
```

行为要点:

- 无体方法(GET/HEAD/OPTIONS,见 `Method::NO_BODY_METHODS`)的数组/对象参数被展平进查询串(递归 `a[b]` 键名,感知 CURLFile);
- 有体方法提取 `PreparedBody` 的 content 并应用其 Content-Type(用户显式头优先)。PUT/PATCH/DELETE 的纯字符串体在未设类型时自动补 `text/plain`(部分服务器拒绝无类型请求体);
- 发送时头名统一小写;未覆盖时注入 `user-agent: ws-http/2.0` 与空 `Expect:`;
- 传输失败(curl errno ≠ 0)抛 `RequestException`,携带 `errno`、`error`、`method`、`url`。

### `Response`

```php
public $code;        // int — HTTP 状态码
public $statusLine;  // string — 如 "HTTP/1.1 200 OK"
public $headers;     // HeaderBag — 大小写不敏感查找
public $rawBody;     // string — 未解码的原始体
public $curlInfo;    // array — 完整 curl_getinfo() 输出
public $body;        // mixed — Content-Type 含 application/json 且解码成功时为解码结果;否则恒为 false
public $jsonError;   // ?array{0: int, 1: string} — JSON 解码失败时的 [errno, message]
public function isOk(): bool        // 200–299
public function header(string $name): ?string  // 大小写不敏感
```

非 JSON 响应与解码失败时 `$body` 保持 `false`——用 `$jsonError` 区分两者。

### `Body`(静态工厂)

```php
Body::json($data, int $options = 0, int $depth = 512): PreparedBody   // Content-Type: application/json
Body::form($data): PreparedBody                                       // application/x-www-form-urlencoded
Body::multipart(array $data, array $files = []): PreparedBody
Body::file(string $filename, string $mimeType = '', string $postName = ''): \CURLFile
Body::raw(string $data, ?string $contentType = null): PreparedBody
```

全部返回 `PreparedBody`(content + 建议 Content-Type;`__toString()` 渲染 content 供诊断)。

## Usage Examples

### 一行 GET

```php
use Ws\Http\Request;

$response = (new Request())->get('https://httpbin.org/get', [], ['name' => 'ws-http']);

echo $response->code;                 // 200
echo $response->body->args->name;     // "ws-http"(JSON 自动解码)
echo $response->header('content-type'); // 大小写不敏感
```

### 带配置的请求(wither 风格)

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
    [],                                    // 额外请求头(可选)
    Body::json(['title' => 'Hello', 'tags' => ['a', 'b']])
);

$response->body->json->title;              // "Hello"
```

### 派生而不修改原对象

```php
$base = new Request();
$authed = $base->withOptions(fn ($o) => $o->withAuth('u', 'p'));

// $base 原封不动;$authed 发送时带 Authorization 头。
```

### Multipart 文件上传

```php
$response = (new Request())->post('https://httpbin.org/post', [], Body::multipart(
    ['field' => 'value'],
    ['upload' => Body::file('/tmp/report.pdf', 'application/pdf', 'report.pdf')]
));
```

### 处理传输异常

```php
use Ws\Http\RequestException;

try {
    (new Request())->get('https://unreachable.invalid/');
} catch (RequestException $e) {
    echo $e->getCode();     // curl errno(如 6 = 无法解析主机)
    echo $e->getMessage();  // 人类可读的 cURL 错误
}
```

## Configuration

- **默认超时**:30 秒(v1 无超时——无限等待);
- **默认 UA**:`ws-http/2.0`;
- **SSL**:默认系统证书库;自定义 bundle 用 `->withCaBundle('/path/to/ca.pem')`;
- **JSON 解码**:默认对象形态;要关联数组用 `withJsonOpts(true)`;
- 完整逐项参考见 [core-configuration.zh-CN.md](./core-configuration.zh-CN.md)。

## Dependencies

- **依赖**:PHP ext-curl / ext-json;`Ws\Http\Method` / `MethodHelper`(方法语义)、`Ws\Http\UrlKit` / `StrKit`(URL 编码、字符串工具)。
- **被依赖**:所有上层——`Assert\Watcher`(对 `Response` 断言)、`Automated\Runner`(场景引擎)、`Plugin\*` 客户端、`NanoGpt\Transport\*`(agent 传输层经 `Plugin\OpenAI\Client` 组合它)。

可运行示例:[examples/httpbin-quick/run.php](../../../examples/httpbin-quick/run.php)。

设计详情:[design/11-HTTP客户端设计.md](../../../design/11-HTTP客户端设计.md)。
