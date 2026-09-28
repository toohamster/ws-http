<?php

declare(strict_types=1);

namespace CcGpt;

use Ws\Http\NanoGpt\Agent;
use Ws\Http\Plugin\OpenAI\Client;

/**
 * 上下文窗口探测(design/21 §8.2 级联层 2):构造提示词让模型 JSON 自述参数。
 *
 * 结果是模型训练记忆,可能不准——只作为默认值供用户裁决(/model 选择后触发),
 * 不直接写档案。解析失败/异常抛给调用方(壳提示手动设置)。
 */
final class ContextWindowProbe
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * 一次探测:返回模型自述的上下文窗口 tokens。
     *
     * @throws \RuntimeException 模型未给出可用数字
     */
    public function probe(string $modelId): int
    {
        $prompt = 'Report your own context window size (maximum total tokens per request) '
            . 'and maximum output tokens. Reply with ONLY a JSON object, no other text: '
            . '{"contextWindow": <int tokens>, "maxOutputTokens": <int tokens>}';
        $response = $this->client->chat()->create(
            [['role' => 'user', 'content' => $prompt]],
            $modelId
        );

        $content = '';
        $body = $response->body;
        if (\is_object($body) && isset($body->choices[0]->message->content)) {
            $content = (string) $body->choices[0]->message->content;
        }

        // 从回答中提取首个 JSON 对象(模型可能裹 ```json 围栏或附加文字)
        if (preg_match('/\{[^{}]*\}/s', $content, $m) !== 1) {
            throw new \RuntimeException('no JSON object in model reply');
        }
        $data = json_decode($m[0], true);
        if (!\is_array($data) || !isset($data['contextWindow']) || !is_numeric($data['contextWindow'])) {
            throw new \RuntimeException('contextWindow missing or non-numeric in reply');
        }

        $window = (int) $data['contextWindow'];
        if ($window <= 0) {
            throw new \RuntimeException('non-positive contextWindow');
        }

        return $window;
    }
}
