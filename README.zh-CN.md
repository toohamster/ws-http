# ws-http

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue) ![License](https://img.shields.io/badge/License-MIT-green) ![Tests](https://img.shields.io/badge/tests-615%20passing-brightgreen) ![Dependencies](https://img.shields.io/badge/dependencies-0-success)

[English](README.md) | [中文版本](README.zh-CN.md)

简单轻量的 cURL HTTP 客户端工具库,附带 API 断言器、场景化自动化测试引擎与多厂商 agent 编排层——**零外部依赖**(仅 ext-curl / ext-json)。

## 四层能力(按需取用)

| 层 | 你能得到 | 一行入口 |
| --- | --- | --- |
| **core** | 一个 cURL HTTP API 工具:SSL / Basic·Digest 认证 / 代理 / Cookie / 自定义头 / JSON·表单·Multipart 体 | `(new Request())->get($url)` |
| **functional** | 断言器(Watcher)、JSONPath 表达式、场景自动化引擎(变量/提取/断言/报告/CLI) | `Watcher::create($response)->assertStatusCode(200)` |
| **plugin** | 三方系统适配(OpenAI 兼容协议、WordPress),注册机制可扩展 | `(new OpenAI\Client($key))->chat()->create('hi')` |
| **NanoGpt** | 多厂商 agent 编排层:agent loop + 工具协议 + 沙箱 + 会话/压缩/记忆,经 ChatTransportInterface 接任意厂商 | `new Agent(new DeepSeekTransport([...]), 'model')` |

> 设计原则:"Simple things should be simple, complex things should be possible." 简单路径永远一行;高级能力(自定义操作符、扩展 source、plugin 注册)只在用到时才出现。

## 需求

- PHP 7.4+(全库 `declare(strict_types=1)`)
- ext-curl、ext-json

## 安装

```shell
composer require toohamster/ws-http
```

## 快速开始

### core:发一个请求

```php
use Ws\Http\Request;

$response = (new Request())->get('https://httpbin.org/get', [], ['name' => 'ws-http']);

$response->code;                   // 200
$response->body->args->name;       // JSON 响应自动解析
$response->header('content-type'); // 大小写不敏感

// 需要配置时(wither 风格,原实例不变):
$request = (new Request())->withOptions(fn ($o) => $o
    ->withTimeout(5)
    ->withAuth('user', 'pass')
    ->withProxy('127.0.0.1', 8787));
```

完整 core 用法(POST/PUT/multipart 文件/cookie/异常处理):[examples/httpbin-quick](examples/httpbin-quick/run.php) · 手册:[docs/reference/zh-CN/core-http-client.zh-CN.md](docs/reference/zh-CN/core-http-client.zh-CN.md)

### functional:断言 + 场景自动化

```php
use Ws\Http\Assert\Watcher;

// 链式断言(失败抛 AssertionException)
Watcher::create($response)
    ->assertStatusCode(200)
    ->assertBody('IS_VALID_JSON')
    ->assertJsonPath('$.result', 'eq', 0);
```

场景脚本(JSON 描述"登录 → 取 token → 下单"这类链式流程):

```jsonc
{
  "id": "smoke", "name": "冒烟测试",
  "outputs": ["token"],                            // 可选:声明后场景即"取数函数",运行完提取结果集
  "variables": [ { "name": "password", "value": "secret", "secret": true } ],
  "steps": [
    { "type": "http", "id": "login", "name": "登录", "url": "https://api.example.com/login",
      "method": "POST", "body": { "mode": "json", "content": "{\"pwd\":\"${password}\"}" },
      "extract": [ { "var": "token", "source": "json", "path": "$.token" } ] },
    { "type": "http", "id": "me", "name": "我的信息", "url": "https://api.example.com/me",
      "method": "GET", "headers": { "Authorization": "Bearer ${token}" },
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ] }
  ]
}
```

```shell
php bin/ws-http run scenario.json                 # 文本报告,失败 exit 1
php bin/ws-http run a.json b.json --format=json   # JSON 报告供 CI
php bin/ws-http run scenario.json --var env=prod  # 外部注入变量
```

手册:[docs/reference/zh-CN/scenario-testing.zh-CN.md](docs/reference/zh-CN/scenario-testing.zh-CN.md) · 编程式调用示例:[examples/scenario](examples/scenario/run.php)

### plugin:三方系统接入

```php
use Ws\Http\Plugin\OpenAI\Client;

// 任何 OpenAI 协议兼容服务:换 base-url 即接入(OpenAI/千帆/DeepSeek/vLLM…)
$client = new Client($apiKey, null, 'https://api.deepseek.com/v1');
$response = $client->chat()->create('hi', 'deepseek-chat');
$response->body->choices[0]->message->content;

// WordPress(Application Password)
$wp = new \Ws\Http\Plugin\WordPress\Client('https://blog.example.com', $appPassword, 'admin');
$wp->posts()->create('标题', '正文');
```

手册:[docs/reference/zh-CN/plugin-system.zh-CN.md](docs/reference/zh-CN/plugin-system.zh-CN.md) · 完整可运行示例:[examples/openai-chat](examples/openai-chat/run.php)

### NanoGpt:多厂商 agent 编排层(`Ws\Http\NanoGpt`)

agent loop(事件流 Generator)+ 工具协议(ToolInterface/ToolRegistry)+ Sandbox 沙箱 + 会话管理(截断/快照/持久化/预算压缩/长期记忆)。编排与传输分离:**编排层不绑定任何厂商**,传输经 `ChatTransportInterface` 可替换——接入新厂商 = 换一个 Transport 类:

```php
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Transport\DeepSeekTransport;   // 内建 7 厂商类 + Generic 逃生门

$agent = new Agent(new DeepSeekTransport(['api_key' => $apiKey]), 'deepseek-chat');
$agent->tools()->register(new \Ws\Http\NanoGpt\Tool\ReadFileTool($sandbox));

foreach ($agent->run('帮我读一下 README.md 并总结') as $event) {
    // text | tool_call | tool_result | error(传输失败带 620–624 语义分类码)
}
```

手册:[agent-core](docs/reference/zh-CN/agent-core.zh-CN.md) · [transports](docs/reference/zh-CN/agent-transports.zh-CN.md) · 参考 CLI:[examples/cc-gpt](examples/cc-gpt/README.md)

## 文档

完整手册索引(16 篇模块参考,双语):**[docs/README.md](docs/README.md)**

| 分区 | 内容 |
| --- | --- |
| Core | HTTP 客户端、配置参考 |
| Functional | 断言、表达式、场景、变量、Cookie、数据驱动、场景输出 |
| NanoGpt | agent 核心、传输层、会话/记忆/技能、cc-gpt 壳 |
| Reference | 错误码(1xx–6xx)、扩展点 |

设计文档(中文,开发者向决策档案):[design/README.md](design/README.md) · 进度台账:[design/PROGRESS.md](design/PROGRESS.md)

## 扩展机制

| 扩展点 | 方式 | 例 |
| --- | --- | --- |
| 自定义断言操作符 | `Comparison::register('op', $impl)` 或 `FnComparator` 闭包包装 | 加解密校验、远程校验 |
| 自定义提取 source | `VarExtractor::registerSource('xml', $impl)` | XML/HTML 提取 |
| 三方系统适配 | 实现 `PluginInterface` + `PluginRegistry::register()` | 新的业务系统客户端 |

机制最小化:注册表 + 接口,无容器/事件/自动发现。手册:[docs/reference/zh-CN/extension-points.zh-CN.md](docs/reference/zh-CN/extension-points.zh-CN.md)

## 开发

```shell
composer install
php74 vendor/bin/phpunit --testsuite Unit         # 纯逻辑
php74 vendor/bin/phpunit --testsuite Engine       # 引擎编排(mock HTTP)
php74 vendor/bin/phpunit --testsuite Integration  # 公网 httpbin(可 skip)
php74 bin/ws-http run tests/fixtures/scenarios/booking-flow.json --format=text  # 冒烟
```

## 2.0 迁移差异(自 1.x)

| 变化 | 说明 |
| --- | --- |
| PHP 7.4+ / strict_types | 不再支持 PHP 5.x;移除 PECL http 依赖与 `@file` 旧上传语法 |
| 默认超时 30s | 1.x 无超时(无限等待) |
| SSL CA 使用系统证书 | 移除内置 ca-bundle.crt;`withCaBundle()` 显式指定 |
| User-Agent `ws-http/2.0` | 1.x 为 `ws-http/1.0` |
| `assertBody` 包含匹配修复 | 1.x `strpos` 参数颠倒导致断言恒错;2.0 语义:体包含期望才通过 |
| `assertHeaders` 大小写不敏感 | 1.x 头名大小写敏感 |
| Body 自动 Content-Type | `Body::json/form/multipart` 返回 `PreparedBody` 自动携带类型(用户显式头优先);PUT/DELETE 字符串体自动补 `text/plain` |
| `ARequest` 静态门面移除 | 统一 `new Request()` |
| 响应对象可诊断 | `Response::$jsonError` 记录 JSON 解析失败详情;传输异常 `RequestException` 含 errno |
| Automated 引擎可用 | 1.x 仅解析骨架;2.0 完整实现提取/断言/报告/CLI |

## License

MIT
