<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

use Ws\Http\Plugin\OpenAI\Client;

/**
 * Agent loop 状态机(design/21 §3):messages+tools → API → tool_calls → 执行 → 回填 → 循环。
 *
 * 事件流(Generator):
 *   ['type' => 'text',        'text' => string]
 *   ['type' => 'tool_call',   'name' => string, 'args' => array]
 *   ['type' => 'tool_result', 'name' => string, 'result' => string]
 *   ['type' => 'error',       'message' => string, 'code' => int]
 *
 * 边界:不直接发请求(复用 Plugin\OpenAI\Client);不做流式;会话保留(传输失败可重试)。
 */
final class Agent
{
    public const DEFAULT_MAX_TURNS = 8;

    /** @var Client 唯一 HTTP 依赖 */
    private $client;

    /** @var string 模型 id */
    private $model;

    /** @var int 防死循环上限 */
    private $maxTurns;

    /** @var ToolRegistry */
    private $tools;

    /** @var Conversation */
    private $conversation;

    public function __construct(Client $client, string $model, int $maxTurns = self::DEFAULT_MAX_TURNS, ?Conversation $conversation = null)
    {
        $this->client = $client;
        $this->model = $model;
        $this->maxTurns = $maxTurns;
        $this->tools = new ToolRegistry();
        $this->conversation = $conversation ?? new Conversation();
    }

    public function tools(): ToolRegistry
    {
        return $this->tools;
    }

    public function conversation(): Conversation
    {
        return $this->conversation;
    }

    /**
     * 运行一轮 agent loop(用户输入 → 产出文本)。
     *
     * @return \Generator<int, array<string, mixed>>
     * @throws AgentException 601 超过 maxTurns / 602 未知名工具(经 error 事件后重抛语义:见 §3.1)
     */
    public function run(string $userInput): \Generator
    {
        $this->conversation->append(['role' => 'user', 'content' => $userInput]);

        for ($turn = 1; $turn <= $this->maxTurns; $turn++) {
            // 传输异常不终止会话:包装为 error 事件,调用方可再次 run 重试
            try {
                $response = $this->client->chat()->create(
                    $this->conversation->forRequest(),
                    $this->model,
                    $this->tools->jsonSchemas() === [] ? [] : ['tools' => $this->tools->jsonSchemas()]
                );
            } catch (\Ws\Http\RequestException $e) {
                yield ['type' => 'error', 'message' => $e->getMessage(), 'code' => $e->getCode()];

                return;
            }

            $body = $response->body;
            if (!\is_object($body) || !isset($body->choices[0]->message)) {
                throw new AgentException('Unexpected chat response structure (body->choices[0]->message missing)', 604);
            }

            if (\is_object($body->usage ?? null)) {
                $this->conversation->accumulateUsage($body->usage);
            }

            $message = $body->choices[0]->message;
            $toolCalls = $message->tool_calls ?? null;

            // b) tool_calls → 逐个执行 → 回填 → 继续循环
            if (\is_array($toolCalls) && $toolCalls !== []) {
                $this->conversation->append([
                    'role'       => 'assistant',
                    'content'    => $message->content ?? null,
                    'tool_calls' => $toolCalls,
                ]);

                foreach ($toolCalls as $call) {
                    $name = (string) ($call->function->name ?? '');
                    $args = json_decode((string) ($call->function->arguments ?? '{}'), true);
                    if (!\is_array($args)) {
                        $args = [];
                    }

                    yield ['type' => 'tool_call', 'name' => $name, 'args' => $args];

                    if (!$this->tools->has($name)) {
                        // 602 语义:未知名工具——回填错误内容让模型纠正,不终止 loop
                        $result = sprintf('error: unknown tool "%s"', $name);
                    } else {
                        try {
                            $result = (string) $this->tools->get($name)->execute($args);
                        } catch (\Throwable $e) {
                            // 工具异常不终止 loop:错误字符串回填(模型可自行纠正)
                            $result = 'error: ' . $e->getMessage();
                        }
                    }

                    yield ['type' => 'tool_result', 'name' => $name, 'result' => $result];

                    $this->conversation->append([
                        'role'         => 'tool',
                        'tool_call_id' => (string) ($call->id ?? ''),
                        'content'      => $result,
                    ]);
                }

                continue; // 回到 POST
            }

            // c) finish_reason=length → 提示截断
            $finishReason = (string) ($body->choices[0]->finish_reason ?? '');
            if ($finishReason === 'length') {
                yield ['type' => 'error', 'message' => 'response truncated (finish_reason=length)', 'code' => 0];
            }

            // a) content → 输出文本,结束本轮
            $content = (string) ($message->content ?? '');
            if ($content !== '') {
                $this->conversation->append(['role' => 'assistant', 'content' => $content]);
                yield ['type' => 'text', 'text' => $content];
            }

            return;
        }

        throw new AgentException(sprintf('Exceeded max turns (%d) without producing text', $this->maxTurns), 601);
    }
}
