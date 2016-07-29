ws-http
========

#### 简单轻量的HTTP 客户端工具库(An Simplified, lightweight HTTP client library)

![ws-http](https://raw.github.com/toohamster/ws-http/master/logo.png)

#### 可用于 HTTP API 测试,支持 ssl,basic auth,代理,自定义请求头,以及常用HTTP 请求方法.(An HTTP API testing framework, written in PHP using curl. Supports ssl, basic auth, passing custom request headers, and most HTTP request methods.

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

#### 查看所有例子(See the full examples) https://github.com/toohamster/ws-http/master/tree/master/tests/Ws/Http/ATest.php.

Requirements
------------

#### PHP ####
版本 5.4.40 以上(Version **5.4.40** or greater).

#### PHP Extensions ####
Curl

Http Request
-----------

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


Http API Watcher
-----------

````php
$watcher = \Ws\Http\Watcher::create($httpResponse);

````

支持的方法(Support Method)
---

````php
$watcher->assertStatusCode($assertedStatusCode);
$watcher->assertTotalTimeLessThan($assertedTime);
$watcher->assertHeadersExist(array $assertedHeaders = []);
$watcher->assertHeaders(array $assertedHeaders = []);
$watcher->assertBody($assertedBody, $useRegularExpression = false);
$watcher->assertBodyJson($asserted, $onNotEqualVarExport = false);
$watcher->assertBodyJsonFile($assertedJsonFile, $onNotEqualPrintJson = false);
````

