# 11 HTTP 客户端细化设计(core:Ws\Http)

> 层归属:**core**。使用者画像:任何需要发 HTTP 请求的 PHP 开发者,无需了解 functional/plugin。

**简单路径**(本层存在的一切复杂度都不影响这几行):

```php
$response = (new \Ws\Http\Request())->get('https://api.github.com');
$response->code;      // 200
$response->body;      // 解析后的 JSON(object),非 JSON 时为 false

// 需要配置时:
$request = (new Request())
    ->withOptions(fn($o) => $o->withTimeout(5)->withAuth('user', 'pass')->withProxy('127.0.0.1', 8787));
$response = $request->post($url, ['Accept' => 'application/json'], Body::json($data));
```

> 范围:`Request` / `RequestOptions` / `HeaderBag` / `Response` / `Body` / `Method` / `RequestException`。
> 旧代码参考:`src/Ws/Http/Request.php`、`Response.php`、`Request/Body.php`;需修复的旧 Bug:B2(verifyHost 误调)、B5(调试代码)、B6(JSON 未自动设 Content-Type)、B8(头大小写敏感)、B10(无默认超时)、B11(异常信息不含 errno)。

## 1. Request

### 1.1 职责与不变量

- 职责:持有 `RequestOptions`,组装 cURL 会话并执行,产出 `Response`;
- 不变量:Request 本身无状态可变配置(配置都在 Options 上),同一 Request 实例可并发派生多次请求;
- 移除旧版基于 id 的单例池(`Request::create($id)`)——按 D1 决策不再保留,统一 `new Request()`。

### 1.2 公开 API 规格

```php
final class Request
{
    public function __construct(?RequestOptions $options = null);

    // 快捷请求(语义见 §1.3)
    public function get(string $url, array $headers = [], array|object|null $parameters = null): Response;
    public function head(string $url, array $headers = [], array|object|null $parameters = null): Response;
    public function options(string $url, array $headers = [], array|object|null $parameters = null): Response;
    public function post(string $url, array $headers = [], mixed $body = null): Response;
    public function put(string $url, array $headers = [], mixed $body = null): Response;
    public function patch(string $url, array $headers = [], mixed $body = null): Response;
    public function delete(string $url, array $headers = [], mixed $body = null): Response;
    public function trace(string $url, array $headers = [], mixed $body = null): Response;

    // 任意方法(标准/自定义,配合 Method 常量)
    public function send(string $method, string $url, mixed $body = null, array $headers = []): Response;

    // 返回当前配置(供调试/测试/引擎派生)
    public function options(): RequestOptions;

    // wither:派生新 Request(共享底层,不改自身)
    public function withOptions(callable $mutator): static;   // fn(RequestOptions $o): RequestOptions

    // 纯函数,公开供测试
    public static function buildHttpQuery(array|object $data, string $parent = ''): array;
    public static function encodeUrl(string $url): string;
    public function formatHeaders(array $headers): string[];
}
```

### 1.3 快捷方法语义表

| 方法 | 第 3 参数去向 | 说明 |
| --- | --- | --- |
| get / head / options | query string | 数组/对象经 `buildHttpQuery` 拍平为 `a[b]=c` 再 `http_build_query`;已含 `?` 的 URL 以 `&` 追加 |
| post / put / patch / delete / trace | 请求体 | 原样交给 send() 的 body 处理(§1.5) |
| send | 由 method 决定 | GET/HEAD/OPTIONS→query,其余→body;与旧版一致 |

### 1.4 send() 执行流程

```
1. body 归一化(§1.5)→ 确定 CURLOPT_POSTFIELDS / query string
2. 组装基础选项(表 §1.6)
3. 叠加 RequestOptions 派生选项(超时/SSL/cookie/auth/proxy/自定义 curlOpts)
4. curl_init → curl_setopt_array → curl_exec
5. 传输失败(curl_errno != 0)→ throw RequestException(errno, error, url, method)
6. 按 curl_info['header_size'] 切分 header/body
7. new Response(statusLine, headers, rawBody, curlInfo, jsonOpts)
```

cURL 句柄不跨请求复用(每次 send 新建),简化连接状态管理;后续性能优化可引入句柄池,不在本期。

### 1.5 body 处理规则

| body 输入 | GET/HEAD/OPTIONS | 其他方法 |
| --- | --- | --- |
| null | 不追加 query | 不发送体 |
| string | 不追加(视为已是完整 URL 的一部分?否——抛 InvalidArgumentException,GET 不接受字符串参数) | 原样发送,不主动设 Content-Type |
| array / object | 拍平进 query | 传给 CURLOPT_POSTFIELDS(数组=cURL 自动 multipart;字符串=原样) |
| 含 CURLFile 的数组 | 拍平时保留 CURLFile 并强制 multipart | 同左 |

### 1.6 默认 cURL 选项表

| 选项 | 默认值 | 说明 |
| --- | --- | --- |
| CURLOPT_RETURNTRANSFER | true | — |
| CURLOPT_FOLLOWLOCATION | true | — |
| CURLOPT_MAXREDIRS | 10 | 可经 options 覆盖 |
| CURLOPT_HEADER | true | 头随体返回,由 Response 切分 |
| CURLOPT_ENCODING | `''` | 接受全部压缩 |
| CURLOPT_SSL_VERIFYPEER | true | — |
| CURLOPT_SSL_VERIFYHOST | 2 | 仅 0/2 合法 |
| CURLOPT_TIMEOUT | **30**(新增默认,修复 B10) | options 可覆盖 |
| CURLOPT_NOSIGNAL | ms<1000 时自动置 1 | 规避 PHP timeout-ms bug |
| CURLOPT_HTTPHEADER | 见 §2 HeaderBag | — |
| User-Agent | `ws-http/2.0` | 未显式设置时 |
| Expect | 空 | 未显式设置时置空,避免 100-continue 延迟 |

用户 curlOpts 的优先级:**显式 curlOpt > RequestOptions 默认 > 上表内置默认**(用数组 `+` 合并方向与旧版相反,必须测试锁定)。

## 2. HeaderBag

修复 B8(响应头大小写敏感)与请求头合并逻辑分散的问题。

```php
final class HeaderBag implements \IteratorAggregate, \Countable
{
    public function __construct(array $headers = []);   // [name => string|string[]]

    public function set(string $name, string|array $value): void;
    public function add(string $name, string $value): void;       // 同名追加为数组
    public function get(string $name): ?string;                   // 大小写不敏感;多值返回逗号拼接
    public function all(string $name): array;                     // 该头的全部值
    public function has(string $name): bool;
    public function remove(string $name): void;
    public function names(): array;                               // 原始大小写名
    public function toArray(): array;                             // [原始名 => string|string[]]
    public function toCurlHeaders(): array;                       // ["name: value", ...] 小写名

    public static function fromRawHeaders(string $raw): self;     // 解析响应原始头块
}
```

行为定义:

1. **归一化规则**:内部以小写名索引,保留首次出现的原始大小写用于输出;
2. **同名多值**:add() 追加为 `string[]`;get() 返回逗号拼接(如 `Set-Cookie` 多值时 get 返回拼接串,all() 取全量);
3. **fromRawHeaders**:`\r\n` 或 `\n` 分行;跳过状态行(状态行解析见 §3);续行(以空白开头)并入上一头;空值头(`Expect:`)保留空串值;
4. **toCurlHeaders**:合并请求侧默认头与本次头后输出,用于 CURLOPT_HTTPHEADER。

## 3. Response

```php
final class Response
{
    public readonly int $code;              // HTTP 状态码
    public readonly string $statusLine;     // "HTTP/1.1 200 OK"(新增,旧版塞在 headers[0])
    public readonly HeaderBag $headers;
    public readonly string $rawBody;
    public readonly array $curlInfo;        // curl_getinfo 全量
    public readonly ?array $jsonError;      // JSON 解析失败时的 [errno, msg](新增)

    public mixed $body;                     // 解析后 body;解析失败或非 JSON 为 false

    public function isOk(): bool;           // 200 <= code < 300
    public function header(string $name): ?string;   // 代理 HeaderBag::get
    public function totalTime(): float;     // curlInfo['total_time'],断言用
}
```

- `body` 解析规则:Content-Type 含 `application/json` → `json_decode` 按 jsonOpts;失败时 `body=false` 且填充 `jsonError`(修复旧版静默失败的不可诊断问题);
- 保留公共属性形态(`$response->code`),引擎与断言器可读取。

## 4. RequestOptions(配置值对象)

集中管理全部请求配置,替代旧版散落在 Request 属性上的 13 个配置方法。

```php
final class RequestOptions
{
    // 以下全部为 wither:返回克隆后的新实例
    public function withTimeout(int $seconds): self;
    public function withTimeoutMs(int $milliseconds): self;
    public function withVerifyPeer(bool $enabled): self;
    public function withVerifyHost(bool $enabled): self;
    public function withCaBundle(string $path): self;          // null = 用系统 CA(新默认,弃用内置旧证书包)
    public function withJsonOpts(bool $assoc, int $depth = 512, int $options = 0): self;
    public function withDefaultHeaders(array $headers): self;   // 批量合并
    public function withDefaultHeader(string $name, string $value): self;
    public function withoutDefaultHeaders(): self;
    public function withCookie(string $cookieString): self;
    public function withCookieFile(string $path): self;          // FILE+JAR 同路径
    public function withAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self;
    public function withProxy(string $address, int $port, int $type = CURLPROXY_HTTP, bool $tunnel = false): self;
    public function withProxyAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self;
    public function withCurlOpts(array $opts): self;
    public function withCurlOpt(int $option, mixed $value): self;
    public function withoutCurlOpts(): self;
    public function withMaxRedirects(int $count): self;

    // 读取器(引擎派生请求时需要)
    public function timeout(): int;
    public function jsonOpts(): array;
    public function defaultHeaders(): HeaderBag;
    // …其余同理,读取器命名去掉 with 前缀
}
```

- CA 默认策略:不再内置 `ca-bundle.crt`(10 年前的证书包);`caBundle=null` 时依赖系统 CA(curl 默认),显式 `withCaBundle()` 可指定;
- 校验:`withTimeout(0)` / 负数抛 `InvalidArgumentException`;`withVerifyHost` 接受 bool(内部映射 0/2),不再暴露非法中间值。

## 5. Body(请求体构造器)

命名空间移到 `Ws\Http\Body`(旧 `Ws\Http\Request\Body` 不保留,D1)。

```php
final class Body
{
    // json:自动补 Content-Type: application/json → 返回 [bodyString, headers] 对
    public static function json(mixed $data, int $options = 0, int $depth = 512): PreparedBody;
    public static function form(array|object $data): PreparedBody;          // Content-Type: application/x-www-form-urlencoded
    public static function multipart(array $data, array $files = []): PreparedBody;  // Content-Type: multipart/form-data
    public static function file(string $filename, string $mimeType = '', string $postName = ''): \CURLFile;
    public static function raw(string $data, string $contentType): PreparedBody;      // 新增:raw-*(引擎 raw-json/xml/text 用)
}

final class PreparedBody   // 新增值对象
{
    public readonly mixed $content;       // string | array( multipart )
    public readonly ?string $contentType; // null = 不设置
}
```

- **修复 B6**:`json/form/multipart` 返回 `PreparedBody`,Content-Type 由 Request 统一应用(用户显式头优先于 PreparedBody 的建议值);
- `file()` 直接返回 CURLFile(PHP 7.4 恒存在,删除 `@file` 旧语法与 curl_file_create 分支);
- `form()` 复用 `Request::buildHttpQuery` 拍平;
- 编码异常:`json_encode` 失败抛 `Exception(code:102)` 含 `json_last_error_msg`。

## 6. Method

保留现有接口常量全集(IANA 全收录),无改动;新增 `Method::isBodyAllowed(string $method): bool` 纯函数辅助(GET/HEAD/CONNECT 不允许体,send() 据此路由参数)。

## 7. 异常与错误处理

```php
class RequestException extends Exception
{
    public readonly int $curlErrno;
    public readonly string $curlError;
    public readonly string $url;
    public readonly string $method;
}
```

- 触发条件:**仅 cURL 传输层失败**(连接拒绝、DNS、超时、SSL 握手);HTTP 4xx/5xx 不抛,返回正常 Response(断言/引擎层决定成败);
- 消息模板:`cURL error {errno}: {error} [{method} {url}]`;
- `Exception` 基类错误码:101=cURL 传输错误,102=响应/编码解析失败。

## 8. 测试要点

| 用例组 | 覆盖 |
| --- | --- |
| buildHttpQuery | 多维数组拍平、对象输入、CURLFile 保留、特殊字符 key |
| encodeUrl | 中文 query、已编码 query 二次编码幂等性、带端口 URL、无 scheme 报错 |
| formatHeaders | 默认头合并、大小写覆盖、UA/Expect 补齐 |
| HeaderBag | 大小写不敏感读写、多值合并、fromRawHeaders(状态行/续行/空值) |
| Response | JSON 自动解析(assoc/depth)、解析失败 jsonError、isOk、totalTime |
| RequestOptions | wither 不可变性、校验异常 |
| send(集成,httpbin) | GET/POST/PUT/DELETE/PATCH、form/json/multipart 文件上传、basic auth、重定向计数、超时异常、代理、cookie 往返 |
