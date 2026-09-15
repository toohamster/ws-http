<?php

/**
 * 示例 2:core 层直接使用(不经过 plugin/functional)
 *
 * 演示"一个 cURL HTTP API 工具"的最短路径:发请求 → 拿响应 → 读字段。
 * 这是任何 PHP 开发者接入本库的第一行代码。
 *
 * 运行:/usr/local/bin/php74 examples/httpbin-quick/run.php
 */

use Ws\Http\Request;

require __DIR__ . '/../bootstrap.php';

// ---------- 1. 最短路径:GET 请求 ----------
$response = (new Request())->get('https://httpbin.org/get', [], ['name' => 'ws-http']);

printf("状态码: %d\n", $response->code);
printf("Content-Type: %s\n", $response->header('content-type') ?? '-');
printf("回显参数 name: %s\n", $response->body->args->name ?? '-');

// ---------- 2. POST JSON ----------
$response = (new Request())->post(
    'https://httpbin.org/post',
    [],
    \Ws\Http\Body::json(['scene' => 'core-usage', 'ts' => time()])
);

printf("\nPOST 回显 data: %s\n", substr((string) $response->body->data, 0, 60));

// ---------- 3. 配置派生(wither):超时 + 认证 + 重试次数 ----------
$request = (new Request())
    ->withOptions(static function (\Ws\Http\RequestOptions $o): \Ws\Http\RequestOptions {
        return $o->withTimeout(10)
            ->withAuth('demo', 'secret')
            ->withMaxRedirects(3);
    });

$response = $request->get('https://httpbin.org/basic-auth/demo/secret');

printf("\nBasic Auth: status=%d authenticated=%s\n", $response->code, $response->body->authenticated ?? '-' ? var_export((bool) ($response->body->authenticated ?? false), true) : '-');

// ---------- 4. 传输层异常(连接失败) ----------
try {
    (new Request())->get('http://127.0.0.1:1/unreachable', [], null);
} catch (\Ws\Http\RequestException $e) {
    printf("\n传输异常(预期): cURL errno=%d, %s\n", $e->curlErrno(), substr($e->getMessage(), 0, 60));
}

echo "\n示例完成:core 层四类形态(GET/POST/配置派生/异常)。\n";
