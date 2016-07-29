ws-http
========

#### 简单轻量的HTTP 客户端工具库(An Simplified, lightweight HTTP client library)

![ws-http](https://raw.github.com/toohamster/ws-http/master/logo.png)

#### 可用于 HTTP API 测试,支持 ssl,basic auth,代理,自定义请求头,以及常用HTTP 请求方法.(An HTTP API testing framework, written in PHP using curl. Supports ssl, basic auth, passing custom request headers, and most HTTP request methods.


## 需求(Requirements)

- [cURL](http://php.net/manual/en/book.curl.php)
- PHP 5.4+

## 安装(Installation)

### 使用 (Using) [Composer](https://getcomposer.org)

在`composer.json`文件中新增如下行(To install ws-http with Composer, just add the following to your `composer.json` file):

```json
{
    "require": {
        "toohamster/ws-http": "*"
    }
}
```

或者手动运行命令(or by running the following command):

```shell
php composer require toohamster/ws-http
```

## Http Request 使用(Http Request Usage)

### 创建一个请求(Creating a Request)

````php
$httpRequest = \Ws\Http\Request::create();
````

支持的方法(Support Method)
---

````php
// set config
$httpRequest->jsonOpts($assoc = false, $depth = 512, $options = 0);
$httpRequest->verifyPeer($enabled);
$httpRequest->verifyHost($enabled);
$httpRequest->verifyFile($file);
$httpRequest->getVerifyFile();
$httpRequest->timeout($seconds);
$httpRequest->defaultHeaders($headers);
$httpRequest->defaultHeader($name, $value);
$httpRequest->clearDefaultHeaders();
$httpRequest->curlOpts($options);
$httpRequest->curlOpt($name, $value);
$httpRequest->clearCurlOpts();
$httpRequest->cookie($cookie);
$httpRequest->cookieFile($cookieFile);
$httpRequest->auth($username = '', $password = '', $method = CURLAUTH_BASIC);
$httpRequest->proxy($address, $port = 1080, $type = CURLPROXY_HTTP, $tunnel = false);
$httpRequest->proxyAuth($username = '', $password = '', $method = CURLAUTH_BASIC);

// http call
$httpRequest->get($url, $headers = [], $parameters = null);
$httpRequest->head($url, $headers = [], $parameters = null);
$httpRequest->options($url, $headers = [], $parameters = null);
$httpRequest->connect($url, $headers = [], $parameters = null);
$httpRequest->post($url, $headers = [], $body = null);
$httpRequest->delete($url, $headers = [], $body = null);
$httpRequest->put($url, $headers = [], $body = null);
$httpRequest->patch($url, $headers = [], $body = null);
$httpRequest->trace($url, $headers = [], $body = null);
````

此处给出一些简单的实例(Let's look at a working example):

```php
$headers = array('Accept' => 'application/json');
$query = array('foo' => 'hello', 'bar' => 'world');

$response = $httpRequest->post('http://mockbin.com/request', $headers, $query);

$response->code;        // 请求响应码(HTTP Status code)
$response->curl_info;   // curl信息(HTTP Curl info)
$response->headers;     // 响应头(Headers)
$response->body;        // 处理后的响应消息体(Parsed body)
$response->raw_body;    // 原始响应消息体(Unparsed body)
```

### JSON 请求(Requests) *(`application/json`)*

```php
$headers = array('Accept' => 'application/json');
$data = array('name' => 'ahmad', 'company' => 'mashape');

$body = Ws\Http\Request\Body::json($data);

$response = $httpRequest->post('http://mockbin.com/request', $headers, $body);
```

**注意(Notes):**
- `Content-Type` 会自动设置成(headers will be automatically set to) `application/json`

### 表单请求(Form Requests) *(`application/x-www-form-urlencoded`)*

```php
$headers = array('Accept' => 'application/json');
$data = array('name' => 'ahmad', 'company' => 'mashape');

$body = Ws\Http\Request\Body::form($data);

$response = $httpRequest->post('http://mockbin.com/request', $headers, $body);
```

**注意(Notes):** 
- `Content-Type` 会自动设置成(headers will be automatically set to) `application/x-www-form-urlencoded`

### Multipart Requests *(`multipart/form-data`)*

```php
$headers = array('Accept' => 'application/json');
$data = array('name' => 'ahmad', 'company' => 'mashape');

$body = Ws\Http\Request\Body::multipart($data);

$response = $httpRequest->post('http://mockbin.com/request', $headers, $body);
```

**注意(Notes):** 

- `Content-Type` 会自动设置成(headers will be automatically set to) `multipart/form-data`.

### 文件上传(Multipart File Upload)

```php
$headers = array('Accept' => 'application/json');
$data = array('name' => 'ahmad', 'company' => 'mashape');
$files = array('bio' => '/path/to/bio.txt', 'avatar' => '/path/to/avatar.jpg');

$body = Ws\Http\Request\Body::multipart($data, $files);

$response = $httpRequest->post('http://mockbin.com/request', $headers, $body);
 ```

```php
$headers = array('Accept' => 'application/json');
$body = array(
    'name' => 'ahmad', 
    'company' => 'mashape'
    'bio' => Ws\Http\Request\Body::file('/path/to/bio.txt', 'text/plain'),
    'avatar' => Ws\Http\Request\Body::file('/path/to/my_avatar.jpg', 'text/plain', 'avatar.jpg')
);

$response = $httpRequest->post('http://mockbin.com/request', $headers, $body);
 ```
 
### 自定义消息体(Custom Body)

可以使用`Ws\Http\Request\Body`类提供的方法来生成消息体或使用PHP自带的序列化函数来生成消息体(Sending a custom body such rather than using the `Ws\Http\Request\Body` helpers is also possible, for example, using a [`serialize`](http://php.net/manual/en/function.serialize.php) body string with a custom `Content-Type`):

```php
$headers = array('Accept' => 'application/json', 'Content-Type' => 'application/x-php-serialized');
$body = serialize((array('foo' => 'hello', 'bar' => 'world'));

$response = $httpRequest->post('http://mockbin.com/request', $headers, $body);
```

### 授权校验(Authentication)

```php
$httpRequest->auth($username, $password, $method);// default is CURLAUTH_BASIC
```

**支持的方法(Supported Methods)**

| Method               | Description                                                                                                                                                                                                     |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `CURLAUTH_BASIC`     | HTTP Basic authentication.  | 
| `CURLAUTH_DIGEST`    | HTTP Digest authentication. as defined in [RFC 2617](http://www.ietf.org/rfc/rfc2617.txt)                                                                                                                       | 
| `CURLAUTH_DIGEST_IE` | HTTP Digest authentication with an IE flavor. *The IE flavor is simply that libcurl will use a special "quirk" that IE is known to have used before version 7 and that some servers require the client to use.* | 
| `CURLAUTH_NEGOTIATE` | HTTP Negotiate (SPNEGO) authentication. as defined in [RFC 4559](http://www.ietf.org/rfc/rfc4559.txt)                                                                                                           |
| `CURLAUTH_NTLM`      | HTTP NTLM authentication. A proprietary protocol invented and used by Microsoft.                                                                                                                                |
| `CURLAUTH_NTLM_WB`   | NTLM delegating to winbind helper. Authentication is performed by a separate binary application. *see [libcurl docs](http://curl.haxx.se/libcurl/c/CURLOPT_HTTPAUTH.html) for more info*                        | 
| `CURLAUTH_ANY`       | This is a convenience macro that sets all bits and thus makes libcurl pick any it finds suitable. libcurl will automatically select the one it finds most secure.                                               |
| `CURLAUTH_ANYSAFE`   | This is a convenience macro that sets all bits except Basic and thus makes libcurl pick any it finds suitable. libcurl will automatically select the one it finds most secure.                                  |
| `CURLAUTH_ONLY`      | This is a meta symbol. OR this value together with a single specific auth value to force libcurl to probe for un-restricted auth and if not, only that single auth algorithm is acceptable.                     |

```php
// custom auth method
$httpRequest->proxyAuth('username', 'password', CURLAUTH_DIGEST);
```

### Cookies

```php
$httpRequest->cookie($cookie)
```

```php
$httpRequest->cookieFile($cookieFile)
```

`$cookieFile` 参数必须是可读取的文件路径(must be a correct path with write permission).

### 请求对象(Request Object)

```php
$httpRequest->get($url, $headers = array(), $parameters = null)
$httpRequest->post($url, $headers = array(), $body = null)
$httpRequest->put($url, $headers = array(), $body = null)
$httpRequest->patch($url, $headers = array(), $body = null)
$httpRequest->delete($url, $headers = array(), $body = null)
```
  
- `url` - 请求地址(Endpoint, address, or uri to be acted upon and requested information from)
- `headers` - 请求头(Request Headers as associative array or object)
- `body` - 请求消息体(Request Body as associative array or object)

可以使用标准的HTTP方法,也可以使用自定义的HTTP方法(You can send a request with any [standard](http://www.iana.org/assignments/http-methods/http-methods.xhtml) or custom HTTP Method):

```php
$httpRequest->send(Ws\Http\Method::LINK, $url, $headers = array(), $body);

$httpRequest->send('CHECKOUT', $url, $headers = array(), $body);
```

### 响应对象(Response Object)

- `code` - 请求响应码(HTTP Status code)
- `curl_info` - HTTP curl信息(HTTP Curl info)
- `headers` - 响应头(HTTP Response Headers)
- `body` - 处理后的响应消息体(Parsed body)
- `raw_body` - 原始响应消息体(Unparsed body)

### 高级设置(Advanced Configuration)

#### 自定义json_decode选项(Custom JSON Decode Flags)

```php
$httpRequest->jsonOpts(true, 512, JSON_NUMERIC_CHECK & JSON_FORCE_OBJECT & JSON_UNESCAPED_SLASHES);
```

#### 超时设置(Timeout)

```php
$httpRequest->timeout(5); // 5s timeout
```

#### 代理(Proxy)

可以设置代理类型(you can also set the proxy type to be one of) `CURLPROXY_HTTP`, `CURLPROXY_HTTP_1_0`, `CURLPROXY_SOCKS4`, `CURLPROXY_SOCKS5`, `CURLPROXY_SOCKS4A`, and `CURLPROXY_SOCKS5_HOSTNAME`.

*check the [cURL docs](http://curl.haxx.se/libcurl/c/CURLOPT_PROXYTYPE.html) for more info*.

```php
// quick setup with default port: 1080
$httpRequest->proxy('10.10.10.1');

// custom port and proxy type
$httpRequest->proxy('10.10.10.1', 8080, CURLPROXY_HTTP);

// enable tunneling
$httpRequest->proxy('10.10.10.1', 8080, CURLPROXY_HTTP, true);
```

##### 代理授权验证 (Proxy Authenticaton)

```php
// basic auth
$httpRequest->proxyAuth('username', 'password', CURLAUTH_DIGEST);
```

#### 缺省请求头 (Default Request Headers)

```php
$httpRequest->defaultHeader('Header1', 'Value1');
$httpRequest->defaultHeader('Header2', 'Value2');
```

批量配置(You can set default headers in bulk by passing an array):

```php
$httpRequest->defaultHeaders(array(
    'Header1' => 'Value1',
    'Header2' => 'Value2'
));
```

清除配置(You can clear the default headers anytime with):

```php
$httpRequest->clearDefaultHeaders();
```

#### 缺省Curl选项 (Default cURL Options)

You can set default [cURL options](http://php.net/manual/en/function.curl-setopt.php) that will be sent on every request:

```php
$httpRequest->curlOpt(CURLOPT_COOKIE, 'foo=bar');
```

批量配置(You can set options bulk by passing an array):

```php
$httpRequest->curlOpts(array(
    CURLOPT_COOKIE => 'foo=bar'
));
```

清除配置(You can clear the default options anytime with):

```php
$httpRequest->clearCurlOpts();
```

#### SSL validation

```php
$httpRequest->verifyPeer(false); // Disables SSL cert validation
```

By default is `true`.


## Http Watcher 使用(Http Watcher Usage)

#### 支持的方法(Support Method)

````php
$watcher = \Ws\Http\Watcher::create($httpResponse);

$watcher->assertStatusCode($assertedStatusCode);
$watcher->assertTotalTimeLessThan($assertedTime);
$watcher->assertHeadersExist(array $assertedHeaders = []);
$watcher->assertHeaders(array $assertedHeaders = []);
$watcher->assertBody($assertedBody, $useRegularExpression = false);
$watcher->assertBodyJson($asserted, $onNotEqualVarExport = false);
$watcher->assertBodyJsonFile($assertedJsonFile, $onNotEqualPrintJson = false);
````

##### 例子(Examples)

````php
$httpRequest = \Ws\Http\Request::create();

$httpResponse = $httpRequest->get("https://api.github.com");
$watcher = \Ws\Http\Watcher::create($httpResponse);

$watcher
         ->assertStatusCode(200)
         ->assertHeadersExist(array(
            "X-GitHub-Request-Id",
            "ETag"
         ))
         ->assertHeaders(array(
            "Server" => "GitHub.com"
         ))
         ->assertBody('IS_VALID_JSON')
         ->assertTotalTimeLessThan(2);
````

````php
$httpRequest = \Ws\Http\Request::create();
$httpResponse = $httpRequest->get("https://freegeoip.net/json/8.8.8.8");
$watcher = \Ws\Http\Watcher::create($httpResponse);

$watcher
         ->assertStatusCode(200)
         ->assertHeadersExist(array(
            "Content-Length"
         ))
         ->assertHeaders(array(
            "Access-Control-Allow-Origin" => "*"
         ))
         ->assertBodyJsonFile(dirname(__DIR__) . "/tests/Ws/Http/_json/freegeoip.net.json");
````

#### 查看所有例子(See the full examples) https://github.com/toohamster/ws-http/blob/master/tests/Ws/Http/ATest.php.
