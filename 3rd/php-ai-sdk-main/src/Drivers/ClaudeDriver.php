<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use SnowmanNunu\Ai\Contracts\AiDriverInterface;
use SnowmanNunu\Ai\DTO\AiResponse;
use SnowmanNunu\Ai\DTO\StreamChunk;
use SnowmanNunu\Ai\DTO\TokenUsage;
use SnowmanNunu\Ai\DTO\ToolCall;
use SnowmanNunu\Ai\Exceptions\AiAuthException;
use SnowmanNunu\Ai\Support\HttpClientFactory;

class ClaudeDriver implements AiDriverInterface
{
    use HandlesGuzzleExceptions;

    protected Client $client;

    protected array $config;

    protected const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    protected const DEFAULT_MODEL = 'claude-sonnet-4-6';

    protected const DEFAULT_MAX_TOKENS = 1024;

    public function __construct(array $config)
    {
        $this->config = $config;
        $config['base_url'] = $config['base_url'] ?? static::DEFAULT_BASE_URL;
        $config['headers'] = $this->getDefaultHeaders();

        $this->client = HttpClientFactory::make($config);
    }

    protected function getDefaultHeaders(): array
    {
        return [
            'x-api-key' => $this->config['api_key'] ?? '',
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ];
    }

    public function chat(array $messages, array $options = []): AiResponse
    {
        $this->ensureApiKey();
        $body = $this->buildRequestBody($messages, $options);

        try {
            $response = $this->client->post('messages', [
                'json' => $body,
            ]);

            return $this->parseChatResponse($response);
        } catch (GuzzleException $e) {
            throw $this->handleGuzzleException($e);
        }
    }

    public function stream(array $messages, array $options = []): Generator
    {
        $this->ensureApiKey();
        $body = $this->buildRequestBody($messages, $options);
        $body['stream'] = true;

        try {
            $response = $this->client->post('messages', [
                'json' => $body,
                'stream' => true,
                'headers' => [
                    'Accept' => 'text/event-stream',
                ],
            ]);

            return $this->parseStreamResponse($response);
        } catch (GuzzleException $e) {
            throw $this->handleGuzzleException($e);
        }
    }

    protected function ensureApiKey(): void
    {
        if (empty($this->config['api_key'])) {
            throw new AiAuthException("API key is required for driver [{$this->getDriverName()}].");
        }
    }

    protected function buildRequestBody(array $messages, array $options = []): array
    {
        $system = '';
        $cleanMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system = $message['content'];

                continue;
            }

            if (isset($message['content']) && is_array($message['content'])) {
                $message['content'] = $this->transformContentParts($message['content']);
            }

            $cleanMessages[] = $message;
        }

        $body = [
            'model' => $options['model'] ?? $this->config['model'] ?? static::DEFAULT_MODEL,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? static::DEFAULT_MAX_TOKENS,
            'messages' => $cleanMessages,
        ];

        if (! empty($system)) {
            $body['system'] = $system;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        if (isset($options['top_p'])) {
            $body['top_p'] = $options['top_p'];
        }

        if (isset($options['tools'])) {
            $body['tools'] = $this->transformTools($options['tools']);
        }

        if (isset($options['tool_choice'])) {
            $body['tool_choice'] = $this->transformToolChoice($options['tool_choice']);
        }

        return $body;
    }

    protected function transformTools(array $tools): array
    {
        $transformed = [];

        foreach ($tools as $tool) {
            if (($tool['type'] ?? '') === 'function' && isset($tool['function'])) {
                $transformed[] = [
                    'name' => $tool['function']['name'] ?? '',
                    'description' => $tool['function']['description'] ?? '',
                    'input_schema' => $tool['function']['parameters'] ?? ['type' => 'object'],
                ];
            } else {
                $transformed[] = $tool;
            }
        }

        return $transformed;
    }

    protected function transformToolChoice(mixed $choice): mixed
    {
        if ($choice === 'auto' || $choice === 'any' || $choice === 'none') {
            return ['type' => $choice];
        }

        if (is_array($choice) && isset($choice['function']['name'])) {
            return ['type' => 'tool', 'name' => $choice['function']['name']];
        }

        return $choice;
    }

    protected function transformContentParts(array $parts): array
    {
        $transformed = [];

        foreach ($parts as $part) {
            $type = $part['type'] ?? '';

            if ($type === 'text') {
                $transformed[] = $part;

                continue;
            }

            if ($type === 'image_url' && isset($part['image_url']['url'])) {
                $url = $part['image_url']['url'];
                [$mediaType, $base64] = $this->parseDataUri($url);

                if ($mediaType !== null) {
                    $transformed[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $base64,
                        ],
                    ];
                }

                continue;
            }

            $transformed[] = $part;
        }

        return $transformed;
    }

    protected function parseDataUri(string $uri): array
    {
        if (! preg_match('/^data:([^;]+);base64,(.+)$/', $uri, $matches)) {
            return [null, null];
        }

        return [$matches[1], $matches[2]];
    }

    protected function parseChatResponse(ResponseInterface $response): AiResponse
    {
        $raw = json_decode($response->getBody()->getContents(), true);

        $content = '';
        $toolCalls = [];

        foreach ($raw['content'] ?? [] as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $content .= $block['text'] ?? '';

                continue;
            }

            if ($type === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'] ?? '',
                    type: 'function',
                    name: $block['name'] ?? '',
                    arguments: json_encode($block['input'] ?? []),
                );
            }
        }

        $usage = $this->parseTokenUsage($raw);

        return new AiResponse(
            content: $content,
            model: $raw['model'] ?? '',
            usage: $usage,
            driver: 'claude',
            raw: $raw,
            toolCalls: $toolCalls ?: null,
            reasoningContent: null,
            finishReason: $raw['stop_reason'] ?? null,
            id: $raw['id'] ?? null,
            created: null,
        );
    }

    protected function parseStreamResponse(ResponseInterface $response): Generator
    {
        $body = $response->getBody();
        $buffer = '';
        $toolCalls = [];

        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            $lines = preg_split('/\r\n|\n|\r/', $buffer);
            $buffer = (string) array_pop($lines);

            foreach ($lines as $line) {
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = ltrim(substr($line, 5));

                if ($data === '[DONE]') {
                    $toolChunk = $this->makeToolCallsChunk($toolCalls);
                    if ($toolChunk !== null) {
                        yield $toolChunk;
                    }
                    yield new StreamChunk(content: '', done: true);

                    return;
                }

                $event = json_decode($data, true);
                if (! is_array($event)) {
                    continue;
                }

                $eventType = $event['type'] ?? '';

                if ($eventType === 'content_block_start') {
                    $block = $event['content_block'] ?? [];
                    if (($block['type'] ?? '') === 'tool_use') {
                        $index = $event['index'] ?? 0;
                        $toolCalls[$index] = [
                            'id' => $block['id'] ?? '',
                            'type' => 'function',
                            'name' => $block['name'] ?? '',
                            'arguments' => '',
                        ];
                    }

                    continue;
                }

                if ($eventType === 'content_block_delta') {
                    $delta = $event['delta'] ?? [];
                    $deltaType = $delta['type'] ?? '';

                    if ($deltaType === 'text_delta' || isset($delta['text'])) {
                        yield new StreamChunk(content: $delta['text'] ?? '', done: false);
                    } elseif ($deltaType === 'input_json_delta') {
                        $index = $event['index'] ?? 0;
                        if (isset($toolCalls[$index]) && isset($delta['partial_json'])) {
                            $toolCalls[$index]['arguments'] .= $delta['partial_json'];
                        }
                    }

                    continue;
                }

                if ($eventType === 'message_stop') {
                    $toolChunk = $this->makeToolCallsChunk($toolCalls);
                    if ($toolChunk !== null) {
                        yield $toolChunk;
                    }
                    yield new StreamChunk(content: '', done: true);

                    return;
                }
            }
        }

        $toolChunk = $this->makeToolCallsChunk($toolCalls);
        if ($toolChunk !== null) {
            yield $toolChunk;
        }
        yield new StreamChunk(content: '', done: true);
    }

    protected function makeToolCallsChunk(array &$toolCalls): ?StreamChunk
    {
        if (empty($toolCalls)) {
            return null;
        }

        $chunk = new StreamChunk(
            content: '',
            done: false,
            toolCalls: $this->buildToolCalls($toolCalls),
        );

        $toolCalls = [];

        return $chunk;
    }

    protected function buildToolCalls(array $accumulated): array
    {
        $result = [];
        foreach ($accumulated as $call) {
            $result[] = new ToolCall(
                id: $call['id'],
                type: $call['type'],
                name: $call['name'],
                arguments: $call['arguments'],
            );
        }

        return $result;
    }

    protected function parseTokenUsage(array $raw): TokenUsage
    {
        $usage = $raw['usage'] ?? [];

        return new TokenUsage(
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            totalTokens: ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
        );
    }

    protected function getDriverName(): string
    {
        return 'claude';
    }
}
