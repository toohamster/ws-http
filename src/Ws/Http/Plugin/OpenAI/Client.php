<?php

declare(strict_types=1);

namespace Ws\Http\Plugin\OpenAI;

use Ws\Http\Body;
use Ws\Http\Request;
use Ws\Http\RequestOptions;
use Ws\Http\Response;

/**
 * OpenAI 语义化客户端(design/17 §4.1 形态:Bearer 认证 + JSON 端点)。
 *
 * 边界:不做消息对象模型/流式协议(扩展预留);方法返回 core Response。
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /** @var Request */
    private $http;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    public function __construct(string $apiKey, ?Request $http = null, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $this->apiKey = $apiKey;
        $this->http = $http ?? new Request();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function chat(): ChatEndpoint
    {
        return new ChatEndpoint($this->http, $this->options(), $this->baseUrl);
    }

    public function models(): ModelsEndpoint
    {
        return new ModelsEndpoint($this->http, $this->options(), $this->baseUrl);
    }

    public function options(): RequestOptions
    {
        return (new BearerAuthProvider())->apply(new RequestOptions(), ['apiKey' => $this->apiKey]);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}

/**
 * POST /chat/completions
 */
final class ChatEndpoint
{
    private $http;
    private $options;
    private $baseUrl;

    public function __construct(Request $http, RequestOptions $options, string $baseUrl)
    {
        $this->http = $http;
        $this->options = $options;
        $this->baseUrl = $baseUrl;
    }

    /**
     * @param string|array $prompt 字符串简写或完整 messages 数组
     * @param array<string, mixed> $extra 附加参数(model 之外的 temperature 等)
     */
    public function create($prompt, string $model = 'gpt-3.5-turbo', array $extra = []): Response
    {
        $messages = \is_string($prompt)
            ? [['role' => 'user', 'content' => $prompt]]
            : $prompt;

        $payload = array_merge(['model' => $model, 'messages' => $messages], $extra);

        return $this->http
            ->withOptions(function (RequestOptions $o): RequestOptions {
                return $this->applyOptions($o);
            })
            ->post($this->baseUrl . '/chat/completions', [], Body::json($payload));
    }

    private function applyOptions(RequestOptions $o): RequestOptions
    {
        return $o->withCurlOpts($this->options->curlOpts())
            ->withDefaultHeaders($this->options->defaultHeaders()->toArray());
    }
}

/**
 * GET /models
 */
final class ModelsEndpoint
{
    private $http;
    private $options;
    private $baseUrl;

    public function __construct(Request $http, RequestOptions $options, string $baseUrl)
    {
        $this->http = $http;
        $this->options = $options;
        $this->baseUrl = $baseUrl;
    }

    public function list(): Response
    {
        return $this->http
            ->withOptions(function (RequestOptions $o): RequestOptions {
                return $this->applyOptions($o);
            })
            ->get($this->baseUrl . '/models');
    }

    private function applyOptions(RequestOptions $o): RequestOptions
    {
        return $o->withCurlOpts($this->options->curlOpts())
            ->withDefaultHeaders($this->options->defaultHeaders()->toArray());
    }
}
