[English](../getting-started.md) | [中文版本](./getting-started.zh-CN.md)

# 快速上手 — 前 5 分钟

> 从 `composer install` 到第一个跑通的场景与 agent 的连续路径。所有片段使用真实签名(经 `src/` 实测)。

## 0. 安装

```shell
composer require toohamster/ws-http
# 要求:PHP 7.4+、ext-curl、ext-json。无其他依赖。
```

## 1. core — 第一个请求(30 秒)

```php
use Ws\Http\Request;

$response = (new Request())->get('https://httpbin.org/get', [], ['name' => 'ws-http']);

echo $response->code;              // 200
echo $response->body->args->name;  // "ws-http"(JSON 自动解码)
```

失败抛 `RequestException` 时,码**就是** curl errno(6 = DNS、7 = 连接被拒、28 = 超时)——见 [error-codes.zh-CN.md](error-codes.zh-CN.md)。

## 2. functional — 断言它(1 分钟)

```php
use Ws\Http\Assert\Watcher;

Watcher::create($response)
    ->assertStatusCode(200)
    ->assertJsonPath('$.args.name', 'eq', 'ws-http');
// 首败抛 AssertionException(3xx 码)。收集式见 assertion.zh-CN.md。
```

## 3. functional — 第一个场景(2 分钟)

保存 `smoke.json`:

```jsonc
{
  "id": "smoke", "name": "冒烟",
  "variables": [ { "name": "password", "value": "secret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "name": "登录", "url": "https://httpbin.org/post",
      "method": "POST", "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" },
      "extract": [ { "var": "token", "source": "json", "path": "$.headers.Accept" } ] },
    { "type": "http", "id": "check", "name": "校验", "url": "https://httpbin.org/status/200",
      "method": "GET",
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
```

```shell
php bin/ws-http run smoke.json    # 仓库检出内(bin/ws-http;不发布 vendor bin)
```

退出码:0 全过 · 1 有失败步骤 · 2 解析错误。完整手册:[scenario-testing.zh-CN.md](scenario-testing.zh-CN.md)。

## 4. NanoGpt — 第一个 agent(2 分钟)

```php
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;   // 或任意厂商类 / GenericOpenAiTransport

$agent = new Agent(new DeepSeekTransport(['api_key' => getenv('DEEPSEEK_API_KEY')]), 'deepseek-chat');

foreach ($agent->run('Reply with exactly one word: pong') as $event) {
    if ($event['type'] === 'text') {
        echo $event['text'], PHP_EOL;   // "pong"
    }
}

echo $agent->conversation()->usage()['total_tokens'], " tokens\n";
```

加一个工具,看循环跑起来:

```php
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Tool\WriteFileTool;

$agent->tools()->register(new WriteFileTool(new Sandbox('/tmp/ws-demo')));

foreach ($agent->run('Create hello.txt containing "hi" using the write_file tool') as $event) {
    // tool_call → tool_result → text
}
```

完整手册:[agent-core.zh-CN.md](agent-core.zh-CN.md) · [agent-transports.zh-CN.md](agent-transports.zh-CN.md) · 参考 CLI:[agent-ccgpt-shell.zh-CN.md](agent-ccgpt-shell.zh-CN.md)

## 排障(首次接触最常见错误)

| 症状 | 可能原因 | 修法 |
| --- | --- | --- |
| `RequestException` 码 6 | 域名解析不了 | 查 URL;走代理?`withProxy()` |
| `RequestException` 码 28 | 超时(默认 30 秒) | `withTimeout()`——端点慢或网络慢 |
| `$response->body === false` | 非 JSON / 解码失败 | 查 `$response->jsonError`;原文在 `rawBody` |
| 场景 exit 2 | 解析错误(V1–V19) | 消息带 JSON Pointer——修那个字段;非法 JSONPath → V9 |
| 场景 405 提取失败 | `onMissing=fail` 且路径无匹配 | 对照真实响应核路径;或 `onMissing: "skip"/"default"` |
| agent 错误码 620 | 认证被拒(401/403) | `api_key` 错/缺;token 过期 |
| agent 错误码 621 | 被限流(429) | 退避;加 `retry: ['max' => 2]`;或 /compact 缩上下文 |
| agent 错误码 624 | 异常响应 | 2xx 但不可解析——查 `base_url` 是否指向 OpenAI 兼容端点 |
| agent 错误码 610 | 沙箱越界 | 工具路径在沙箱根之外——改用沙箱内路径 |
| 工具从不被调用 | 模型没看到工具或用不了 | 工具注册了吗?Schema 准吗?模型支持 function calling 吗?(机制讲解见 `docs/`) |

## 下一步

- Core 深入:[core-http-client.zh-CN.md](core-http-client.zh-CN.md) · [core-configuration.zh-CN.md](core-configuration.zh-CN.md)
- 测试深入:[scenario-testing.zh-CN.md](scenario-testing.zh-CN.md) · [data-driven-testing.zh-CN.md](data-driven-testing.zh-CN.md) · [scenario-outputs.zh-CN.md](scenario-outputs.zh-CN.md)
- Agent 深入:[agent-transports.zh-CN.md](agent-transports.zh-CN.md) · [agent-session.zh-CN.md](agent-session.zh-CN.md)
- 扩展:[extension-points.zh-CN.md](extension-points.zh-CN.md) · 完整索引:[docs/README.md](../../README.md)
