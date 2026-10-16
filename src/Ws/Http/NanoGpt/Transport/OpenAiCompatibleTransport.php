<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\ChatResult;
use Ws\Http\NanoGpt\ModelInfo;
use Ws\Http\Plugin\OpenAI\Client as OpenAIClient;

/**
 * OpenAI 协议族传输基类(design/28 §5 维度 A,抽象——不直接实例化为某厂商)。
 *
 * 承载协议族共性:请求组装(组合 Plugin\OpenAI\Client)/ChatResult 解析/
 * 重试完整语义(§3)/错误语义分类(§4)。差异槽(protected):BASE_URL /
 * DEFAULT_MODEL——厂商子类(deepseek/qwen/kimi/moonshot/minimax/orcarouter/openai)
 * 覆盖常量即完成接入;厂商特殊逻辑覆写差异槽方法,**基类内禁止厂商分支**。
 *
 * 错误分类码(design/28 §4,AgentException 600 段传输子码):
 *   620 auth / 621 rate-limit / 622 server / 623 timeout / 624 unexpected-response
 */
abstract class OpenAiCompatibleTransport implements ChatTransportInterface
{
    /** @var string 协议族默认端点(厂商子类覆盖) */
    protected const BASE_URL = 'https://api.openai.com/v1';

    /** @var string 协议族默认模型(厂商子类覆盖) */
    protected const DEFAULT_MODEL = 'gpt-4o-mini';

    /** @var int 请求超时秒 */
    protected const TIMEOUT = 60;

    /** @var int[] 默认触发重试的状态码 */
    protected const RETRY_ON_STATUS = [429, 500, 502, 503, 504];

    /** @var OpenAIClient */
    private $client;

    /** @var string */
    private $apiKey;

    /** @var string 构造期归一后的 baseUrl */
    private $baseUrl;

    /**
     * @param array<string, mixed> $config:api_key(必填)/ base_url(缺省 = BASE_URL 常量)/
     *        timeout(缺省 = TIMEOUT)/ http(测试注入 Request)
     */
    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? static::BASE_URL), '/');
        $timeout = (int) ($config['timeout'] ?? static::TIMEOUT);

        $http = $config['http'] ?? null;
        $this->client = new OpenAIClient(
            $this->apiKey,
            $http instanceof \Ws\Http\Request ? $http : null,
            $this->baseUrl
        );
        unset($timeout); // 首版经 Client 默认传输;超时策略随 D2 error 透传细化
    }

    /**
     * 厂商默认模型(差异槽;capabilities/defaultModel 用)。
     */
    protected function defaultModel(): string
    {
        return static::DEFAULT_MODEL;
    }

    /**
     * 请求级模型解析:options['model'](调用方覆盖,Agent 每次传入)优先,
     * 缺省回落厂商默认(design/28 §1:model 属 Agent 构造,Transport 常量为兜底)。
     *
     * @param array<string, mixed> $options
     */
    private function resolveModel(array $options): string
    {
        $model = $options['model'] ?? '';

        return \is_string($model) && $model !== '' ? $model : $this->defaultModel();
    }

    public function chat(array $messages, array $options = []): ChatResult
    {
        $retry = $this->normalizeRetry($options['retry'] ?? []);
        $attempt = 0;
        $lastStatus = 0;
        $lastMessage = '';

        while (true) {
            try {
                $response = $this->client->chat()->create($messages, $this->resolveModel($options), $this->extraOptions($options));
            } catch (\Ws\Http\RequestException $e) {
                // 传输层异常(连接失败/超时)→ 映射;不重试传输异常(幂等性不可保证,只重试可重试状态码)
                throw $this->mapException($e, 0, $e->getMessage());
            }

            $httpStatus = $response->code;
            if ($httpStatus >= 200 && $httpStatus < 300) {
                $result = ChatResult::fromBody(\is_object($response->body) ? $response->body : null);
                if ($this->isParseable($response)) {
                    return $result;
                }
                // 2xx 但结构不可解析 → 624(不重试:重发同请求结果相同)
                throw new AgentException('Unexpected chat response structure', 624);
            }

            $lastStatus = $httpStatus;
            $lastMessage = $this->errorMessage($response);

            if ($attempt < $retry['max'] && in_array($httpStatus, $retry['on_status'], true)) {
                usleep($this->backoffMicroseconds($attempt, $response));
                $attempt++;

                continue;
            }

            throw $this->mapStatus($httpStatus, $lastMessage);
        }
    }

    /**
     * 响应结构可判定(供 624 分类;子类可覆写处理特殊响应形态)。
     */
    protected function isParseable(\Ws\Http\Response $response): bool
    {
        return \is_object($response->body) && isset($response->body->choices);
    }

    /**
     * 采样参数透传(差异槽;厂商子类可覆写注入自家参数)。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function extraOptions(array $options): array
    {
        $extra = [];
        foreach (['max_tokens', 'temperature', 'top_p', 'tools'] as $k) {
            if (isset($options[$k])) {
                $extra[$k] = $options[$k];
            }
        }

        return $extra;
    }

    /**
     * @return array{max: int, delay_ms: int, multiplier: float, on_status: int[]}
     */
    private function normalizeRetry(array $retry): array
    {
        return [
            'max'        => max(0, (int) ($retry['max'] ?? 0)),
            'delay_ms'   => max(0, (int) ($retry['delay_ms'] ?? 1000)),
            'multiplier' => max(1.0, (float) ($retry['multiplier'] ?? 2.0)),
            'on_status'  => \is_array($retry['on_status'] ?? null) ? array_map('intval', $retry['on_status']) : static::RETRY_ON_STATUS,
        ];
    }

    /**
     * 退避微秒:Retry-After 头(秒)优先;否则 delay_ms * multiplier^attempt。
     */
    private function backoffMicroseconds(int $attempt, \Ws\Http\Response $response): int
    {
        foreach ($response->headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                $seconds = (int) (\is_array($value) ? ($value[0] ?? 0) : $value);

                return max(0, $seconds) * 1000000;
            }
        }

        $delayMs = (int) ($this->normalizeRetry([])['delay_ms'] * pow($this->normalizeRetry([])['multiplier'], $attempt));

        return $delayMs * 1000;
    }

    /**
     * HTTP 状态码 → 语义分类(design/28 §4)。
     */
    private function mapStatus(int $status, string $message): AgentException
    {
        if ($status === 401 || $status === 403) {
            return new AgentException('transport auth failure: ' . $message, 620);
        }
        if ($status === 429) {
            return new AgentException('transport rate limited: ' . $message, 621);
        }
        if ($status >= 500) {
            return new AgentException('transport server error: ' . $message, 622);
        }

        return new AgentException('transport unexpected status ' . $status . ': ' . $message, 624);
    }

    /**
     * RequestException → 分类(连接/超时类 → 623;其余按 code 兜底 624)。
     */
    private function mapException(\Ws\Http\RequestException $e, int $status, string $message): AgentException
    {
        // PHP cURL 层超时在 Request 的 curl info/errno 侧;此处以消息特征粗分,
        // D2 Agent 透传后可细化
        $lower = strtolower($message);
        if (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false) {
            return new AgentException('transport timeout: ' . $message, 623, $e);
        }

        return new AgentException('transport failure: ' . $message, 624, $e);
    }

    /**
     * 从错误响应提取 message(各家错误体形状经 兜底)。
     */
    private function errorMessage(\Ws\Http\Response $response): string
    {
        $body = $response->body;
        if (\is_object($body) && isset($body->error->message)) {
            return (string) $body->error->message;
        }

        return mb_substr((string) $response->rawBody, 0, 200);
    }

    public function capabilities(): array
    {
        $models = [];

        try {
            $response = $this->client->models()->list();
            $body = $response->body;
            if (\is_object($body) && isset($body->data) && \is_array($body->data)) {
                foreach ($body->data as $m) {
                    if (!\is_object($m)) {
                        continue;
                    }
                    $info = $this->modelFromEntry($m);
                    if ($info !== null) {
                        $models[] = $info;
                    }
                }
            }
        } catch (\Throwable $e) {
            $models = []; // 目录获取失败 = 空目录(模型可手动指定,不阻塞对话)
        }

        return ['models' => $models, 'contextWindow' => null, 'maxOutputTokens' => null];
    }

    /**
     * 目录条目 → ModelInfo(差异槽;厂商子类覆写做条目过滤/字段归一,
     * 返回 null = 过滤该条目)。基类:全量无过滤,id 必需,pricing 标量直取。
     *
     * @param object $entry /models data 原始条目
     */
    protected function modelFromEntry(object $entry): ?ModelInfo
    {
        $id = (string) ($entry->id ?? '');
        if ($id === '') {
            return null;
        }

        $pricing = isset($entry->pricing) && \is_scalar($entry->pricing) ? (string) $entry->pricing : '';

        return new ModelInfo($id, (string) ($entry->name ?? $id), $pricing, null, null, ModelInfo::SRC_API);
    }
}
