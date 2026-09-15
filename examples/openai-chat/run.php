<?php

/**
 * 示例 1:OpenAI 协议兼容 Chat 调用(plugin 层最短路径)
 *
 * 演示:
 * 1. OpenAI plugin 语义化客户端的一行式调用(简单路径);
 * 2. 兼容端点接入(千帆等 OpenAI 协议服务):只换 base-url;
 * 3. 多轮对话、流式关闭、错误处理(Watcher 断言与 plugin 的组合)。
 *
 * 运行:
 *   cp examples/openai-chat/config.example.php examples/openai-chat/config.php
 *   # 编辑 config.php 填入真实 api-key(或用环境变量 WS_HTTP_OPENAI_KEY 等)
 *   /usr/local/bin/php74 examples/openai-chat/run.php
 */

use Ws\Http\Assert\Watcher;
use Ws\Http\Plugin\OpenAI\Client;

require __DIR__ . '/../bootstrap.php';

// ---------- 配置(环境变量 > config.php > 默认) ----------
$defaults = require __DIR__ . '/config.example.php';
if (is_file(__DIR__ . '/config.php')) {
    $defaults = array_merge($defaults, require __DIR__ . '/config.php');
}

$baseUrl = (string) $defaults['base-url'];
$apiKey = (string) $defaults['api-key'];
$model = (string) $defaults['model'];

if ($apiKey === '' || $apiKey === 'sk-your-key-here') {
    fwrite(STDERR, "请先配置 api-key:复制 config.example.php 为 config.php,或设置环境变量 WS_HTTP_OPENAI_KEY\n");
    exit(1);
}

printf("端点: %s\n模型: %s\n\n", $baseUrl, $model);

// ---------- 1. 语义化客户端(简单路径) ----------
$client = new Client($apiKey, null, $baseUrl);

// 2. 单轮对话:一行调用
$response = $client->chat()->create('用一句话介绍你自己', $model);

// 3. 用 Watcher 断言响应(plugin 返回 core Response,断言器直接可用)
Watcher::create($response)
    ->assertStatusCode(200)
    ->assertBody('IS_VALID_JSON');

echo "【单轮】", trim((string) ($response->body->choices[0]->message->content ?? '(空)')), "\n\n";

// ---------- 4. 多轮对话 ----------
$response = $client->chat()->create([
    ['role' => 'system', 'content' => '你是一个简明的技术助手,回答不超过 30 字。'],
    ['role' => 'user', 'content' => 'HTTP 302 是什么含义?'],
    ['role' => 'assistant', 'content' => '临时重定向。'],
    ['role' => 'user', 'content' => '那 301 呢?和它有何区别?'],
], $model);

echo "【多轮】", trim((string) ($response->body->choices[0]->message->content ?? '(空)')), "\n\n";

// ---------- 5. 用量信息提取(表达式引擎直接消费 Response->body) ----------
$usage = $response->body->usage ?? null;
if ($usage !== null) {
    printf("【用量】prompt=%s completion=%s total=%s\n",
        $usage->prompt_tokens ?? '-',
        $usage->completion_tokens ?? '-',
        $usage->total_tokens ?? '-'
    );
}

// ---------- 6. 错误形态演示:错误的 model 名 → 4xx,不抛异常,由断言判定 ----------
$bad = $client->chat()->create('ping', 'no-such-model-xxx');
printf("\n【错误形态】无效 model → status=%d isOk=%s\n", $bad->code, $bad->isOk() ? 'true' : 'false');
if (!$bad->isOk() && isset($bad->body->error->message)) {
    printf("服务端错误信息: %s\n", $bad->body->error->message);
}

echo "\n示例完成:core(Request)/functional(Watcher)/plugin(OpenAI) 三层在同一脚本中协作。\n";
