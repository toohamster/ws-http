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

class OpenAiDriver implements AiDriverInterface
{
    use HandlesGuzzleExceptions;

    protected Client $client;

    protected array $config;

    protected const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    protected const DEFAULT_MODEL = 'gpt-4o-mini';

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
            'Authorization' => 'Bearer '.($this->config['api_key'] ?? ''),
            'Content-Type' => 'application/json',
        ];
    }

    public function chat(array $messages, array $options = []): AiResponse
    {
        $this->ensureApiKey();
        $body = $this->buildRequestBody($messages, $options);

        try {
            $response = $this->client->post('chat/completions', [
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
            $response = $this->client->post('chat/completions', [
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
        $body = [
            'model' => $options['model'] ?? $this->config['model'] ?? static::DEFAULT_MODEL,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? static::DEFAULT_MAX_TOKENS,
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        if (isset($options['top_p'])) {
            $body['top_p'] = $options['top_p'];
        }

        if (isset($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        if (isset($options['tool_choice'])) {
            $body['tool_choice'] = $options['tool_choice'];
        }

        return $body;
    }

    protected function parseChatResponse(ResponseInterface $response): AiResponse
    {
        $raw = json_decode($response->getBody()->getContents(), true);

        $choice = $raw['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';

        $toolCalls = [];
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $call) {
                $toolCalls[] = new ToolCall(
                    id: $call['id'] ?? '',
                    type: $call['type'] ?? 'function',
                    name: $call['function']['name'] ?? '',
                    arguments: $call['function']['arguments'] ?? '',
                );
            }
        }

        $usage = $this->parseTokenUsage($raw);

        return new AiResponse(
            content: $content,
            model: $raw['model'] ?? '',
            usage: $usage,
            driver: $this->getDriverName(),
            raw: $raw,
            toolCalls: $toolCalls ?: null,
            reasoningContent: $message['reasoning_content'] ?? null,
            finishReason: $choice['finish_reason'] ?? null,
            id: $raw['id'] ?? null,
            created: isset($raw['created']) ? (int) $raw['created'] : null,
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

                if (isset($event['choices'][0]['finish_reason'])) {
                    $toolChunk = $this->makeToolCallsChunk($toolCalls);
                    if ($toolChunk !== null) {
                        yield $toolChunk;
                    }
                    yield new StreamChunk(content: '', done: true);

                    return;
                }

                $delta = $event['choices'][0]['delta'] ?? [];
                $content = $delta['content'] ?? '';
                $reasoning = $delta['reasoning_content'] ?? null;

                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $callDelta) {
                        $index = $callDelta['index'] ?? 0;
                        if (! isset($toolCalls[$index])) {
                            $toolCalls[$index] = ['id' => '', 'type' => 'function', 'name' => '', 'arguments' => ''];
                        }

                        if (isset($callDelta['id'])) {
                            $toolCalls[$index]['id'] = $callDelta['id'];
                        }
                        if (isset($callDelta['type'])) {
                            $toolCalls[$index]['type'] = $callDelta['type'];
                        }
                        if (isset($callDelta['function']['name'])) {
                            $toolCalls[$index]['name'] = $callDelta['function']['name'];
                        }
                        if (isset($callDelta['function']['arguments'])) {
                            $toolCalls[$index]['arguments'] .= $callDelta['function']['arguments'];
                        }
                    }
                }

                if ($content !== '' || $reasoning !== null) {
                    yield new StreamChunk(
                        content: $content,
                        done: false,
                        reasoningContent: $reasoning,
                    );
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
            inputTokens: $usage['prompt_tokens'] ?? 0,
            outputTokens: $usage['completion_tokens'] ?? 0,
            totalTokens: ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0),
        );
    }

    protected function getDriverName(): string
    {
        return 'openai';
    }
}
