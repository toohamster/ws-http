<?php

declare(strict_types=1);

/**
 * N4 冒烟(任务 7 第 1 步):orcarouter 真网验证——一次脚本跑完三链,全量回报,不中途再跑。
 *
 * 链路:① /models(free 档案提取) ② 纯文本 chat(消息结构/usage) ③ tools 对话(function calling 支持)
 * key 从环境变量 CC_GPT_API_KEY 读,不落盘。
 */

require __DIR__ . '/../../vendor/autoload.php';

use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Tool\WriteFileTool;
use Ws\Http\Plugin\OpenAI\Client;

$apiKey = getenv('CC_GPT_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "set CC_GPT_API_KEY first\n");
    exit(2);
}

$baseUrl = getenv('CC_GPT_BASE_URL') ?: 'https://api.orcarouter.ai/v1';
$freeModel = getenv('CC_GPT_MODEL') ?: 'deepseek/deepseek-v4-flash-free';

$client = new Client($apiKey, null, $baseUrl);

echo "=== ① /models:free 模型档案 ===\n";
$response = $client->models()->list();
$body = $response->body;
printf("code=%d total=%d\n", $response->code, \is_object($body) && isset($body->data) ? \count($body->data) : -1);

$freeProfiles = [];
if (\is_object($body) && isset($body->data) && \is_array($body->data)) {
    foreach ($body->data as $m) {
        if (stripos((string) ($m->id ?? ''), 'free') === false) {
            continue;
        }
        $freeProfiles[] = [
            'id'                    => $m->id,
            'name'                  => $m->name ?? null,           // 稀疏字段容错(先例:orcarouter/free 无 name)
            'context_length'        => $m->context_length ?? null,
            'max_completion_tokens' => $m->max_completion_tokens ?? null,
            'pricing'               => isset($m->pricing) ? get_object_vars($m->pricing) : null,
        ];
    }
}
echo json_encode($freeProfiles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

echo "\n=== ② chat 纯文本:消息结构 / usage / finish_reason ===\n";
$chat = $client->chat()->create([['role' => 'user', 'content' => '只回复两个字:收到']], $freeModel);
printf("code=%d\n", $chat->code);
$cb = $chat->body;
echo json_encode([
    'has_choices'   => isset($cb->choices[0]->message),
    'content'       => isset($cb->choices[0]->message->content) ? mb_substr((string) $cb->choices[0]->message->content, 0, 60) : null,
    'finish_reason' => $cb->choices[0]->finish_reason ?? null,
    'usage'         => isset($cb->usage) ? get_object_vars($cb->usage) : null,
], JSON_UNESCAPED_UNICODE), "\n";

echo "\n=== ③ tools 对话(function calling 支持)——Agent loop 带真实工具 ===\n";
$root = sys_get_temp_dir() . '/ws-n4-' . uniqid();
$agent = new Agent($client, $freeModel);
$agent->tools()->register(new WriteFileTool(new Sandbox($root)));

$events = [];
$maxTurnsHit = false;
try {
    foreach ($agent->run('请把文本 "n4-smoke-ok" 写入文件 result.txt,然后告诉我完成没有') as $event) {
        $events[] = $event;
        if ($event['type'] === 'tool_call') {
            printf("  [tool_call] %s %s\n", $event['name'], json_encode($event['args'], JSON_UNESCAPED_UNICODE));
        } elseif ($event['type'] === 'tool_result') {
            printf("  [tool_result] %s\n", mb_substr((string) $event['result'], 0, 80));
        } elseif ($event['type'] === 'text') {
            printf("  [text] %s\n", mb_substr($event['text'], 0, 120));
        } elseif ($event['type'] === 'error') {
            printf("  [error] %s\n", $event['message']);
        }
    }
} catch (\Ws\Http\NanoGpt\AgentException $e) {
    $maxTurnsHit = ($e->getCode() === 601);
    printf("  [AgentException %d] %s\n", $e->getCode(), $e->getMessage());
}

$types = array_column($events, 'type');
echo json_encode([
    'event_sequence'   => $types,
    'tool_was_called'  => \in_array('tool_call', $types, true),
    'final_text'       => \in_array('text', $types, true),
    'max_turns_hit'    => $maxTurnsHit,
    'file_written'     => is_file($root . '/result.txt') ? file_get_contents($root . '/result.txt') : null,
    'usage_accumulated' => $agent->conversation()->usage(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

exec('rm -rf ' . escapeshellarg($root));

echo "\n=== 冒烟结论 ===\n";
$verdict = [
    'models_chain'   => \count($freeProfiles) > 0,
    'chat_chain'     => isset($cb->choices[0]->message),
    'tools_chain'    => \in_array('tool_call', $types, true) && \in_array('tool_result', $types, true),
];
echo json_encode($verdict, JSON_PRETTY_PRINT), "\n";
exit($verdict['models_chain'] && $verdict['chat_chain'] ? 0 : 1);
