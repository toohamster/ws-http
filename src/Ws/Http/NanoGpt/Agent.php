<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

use Ws\Http\NanoGpt\Transport\ChatTransportInterface;

/**
 * Agent loop 状态机(design/21 §3;design/28 D2 修订):messages+tools → API → tool_calls → 执行 → 回填 → 循环。
 *
 * 事件流(Generator):
 *   ['type' => 'text',        'text' => string]
 *   ['type' => 'tool_call',   'name' => string, 'args' => array]
 *   ['type' => 'tool_result', 'name' => string, 'result' => string]
 *   ['type' => 'error',       'message' => string, 'code' => int]
 *
 * 依赖:唯一传输依赖 = ChatTransportInterface(design/28 §2 编排与传输分离,
 * 编排层源码零厂商名);响应解析在 Transport 内收口为 ChatResult。
 * 传输失败(AgentException 620–624 语义分类)不终止会话:包装为 error 事件
 * (code = 分类码),调用方可再次 run 重试。
 */
final class Agent
{
    public const DEFAULT_MAX_TURNS = 8;

    /** @var ChatTransportInterface 唯一传输依赖(翻译边界) */
    private $transport;

    /** @var string 模型 id */
    private $model;

    /** @var int 防死循环上限 */
    private $maxTurns;

    /** @var ToolRegistry */
    private $tools;

    /** @var Conversation */
    private $conversation;

    public function __construct(ChatTransportInterface $transport, string $model, int $maxTurns = self::DEFAULT_MAX_TURNS, ?Conversation $conversation = null)
    {
        $this->transport = $transport;
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
     * @throws AgentException 601 超过 maxTurns / 604 结构不可解析(Transport 已判 624 时不会出现)
     */
    public function run(string $userInput): \Generator
    {
        $this->conversation->append(['role' => 'user', 'content' => $userInput]);

        for ($turn = 1; $turn <= $this->maxTurns; $turn++) {
            // 传输异常不终止会话:语义分类 code(620–624)进 error 事件,调用方可再次 run 重试
            try {
                $result = $this->transport->chat(
                    $this->conversation->forRequest(),
                    $this->buildOptions()
                );
            } catch (AgentException $e) {
                yield ['type' => 'error', 'message' => $e->getMessage(), 'code' => $e->getCode()];

                return;
            }

            if ($result->hasUsage()) {
                $this->conversation->accumulateUsage((object) [
                    'prompt_tokens'     => $result->usage()['prompt_tokens'],
                    'completion_tokens' => $result->usage()['completion_tokens'],
                    'total_tokens'      => $result->usage()['total_tokens'],
                ]);
            }

            // b) tool_calls → 逐个执行 → 回填 → 继续循环
            if ($result->toolCalls() !== []) {
                $this->conversation->append([
                    'role'       => 'assistant',
                    'content'    => $result->content() !== '' ? $result->content() : null,
                    'tool_calls' => array_map(static fn (array $c) => $c['wire'], $result->toolCalls()),
                ]);

                foreach ($result->toolCalls() as $call) {
                    $name = $call['name'];
                    $args = json_decode((string) ($call['arguments'] ?? '{}'), true);
                    if (!\is_array($args)) {
                        $args = [];
                    }

                    yield ['type' => 'tool_call', 'name' => $name, 'args' => $args];

                    if (!$this->tools->has($name)) {
                        // 602 语义:未知名工具——回填错误内容让模型纠正,不终止 loop
                        $toolResult = sprintf('error: unknown tool "%s"', $name);
                    } else {
                        try {
                            $toolResult = (string) $this->tools->get($name)->execute($args);
                        } catch (\Throwable $e) {
                            // 工具异常不终止 loop:错误字符串回填(模型可自行纠正)
                            $toolResult = 'error: ' . $e->getMessage();
                        }
                    }

                    yield ['type' => 'tool_result', 'name' => $name, 'result' => $toolResult];

                    $this->conversation->append([
                        'role'         => 'tool',
                        'tool_call_id' => $call['id'],
                        'content'      => $toolResult,
                    ]);
                }

                continue; // 回到 chat
            }

            // c) finish_reason=length → 提示截断
            if ($result->finishReason() === 'length') {
                yield ['type' => 'error', 'message' => 'response truncated (finish_reason=length)', 'code' => 0];
            }

            // a) content → 输出文本,结束本轮
            $content = $result->content();
            if ($content !== '') {
                $this->conversation->append(['role' => 'assistant', 'content' => $content]);
                yield ['type' => 'text', 'text' => $content];
            }

            return;
        }

        throw new AgentException(sprintf('Exceeded max turns (%d) without producing text', $this->maxTurns), 601);
    }

    /**
     * 每次请求的 options:tools schemas(空则不带)+ model(请求级覆盖位——
     * Transport 端 options['model'] ?? 厂商默认;design/28 §1 简单路径 model 属 Agent 构造)。
     *
     * @return array<string, mixed>
     */
    private function buildOptions(): array
    {
        $options = [];
        if ($this->tools->jsonSchemas() !== []) {
            $options['tools'] = $this->tools->jsonSchemas();
        }
        $options['model'] = $this->model;

        return $options;
    }
}
