<?php

declare(strict_types=1);

namespace Ws\Http\Plugin\WordPress;

use Ws\Http\Request;
use Ws\Http\RequestOptions;
use Ws\Http\Response;

/**
 * WordPress REST v2 语义化客户端(design/17 §4.2 形态:Basic 认证 + REST 端点)。
 */
final class Client
{
    /** @var Request */
    private $http;

    /** @var string */
    private $siteUrl;

    /** @var string */
    private $user;

    /** @var string */
    private $appPassword;

    public function __construct(string $siteUrl, string $appPassword, ?string $user = null, ?Request $http = null)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->appPassword = $appPassword;
        $this->user = $user ?? '';
        $this->http = $http ?? new Request();
    }

    public function posts(): PostsEndpoint
    {
        return new PostsEndpoint(
            $this->http,
            ['user' => $this->user, 'appPassword' => $this->appPassword],
            $this->restBase()
        );
    }

    public function media(): MediaEndpoint
    {
        return new MediaEndpoint(
            $this->http,
            ['user' => $this->user, 'appPassword' => $this->appPassword],
            $this->restBase()
        );
    }

    public function restBase(): string
    {
        return $this->siteUrl . '/wp-json/wp/v2';
    }

    /**
     * wp_error 检测的便捷判断(响应体含 code/message 即 WP 错误)。
     */
    public static function isWpError(Response $response): bool
    {
        return isset($response->body->code, $response->body->message);
    }
}

/**
 * /wp-json/wp/v2/posts
 */
final class PostsEndpoint
{
    /** @var Request */
    private $http;

    /** @var array<string, string> [user, appPassword] */
    private $authConfig;

    /** @var string */
    private $restBase;

    /**
     * @param array<string, string> $authConfig
     */
    public function __construct(Request $http, array $authConfig, string $restBase)
    {
        $this->http = $http;
        $this->authConfig = $authConfig;
        $this->restBase = $restBase;
    }

    /**
     * @param array<string, mixed> $query per_page/status 等
     */
    public function list(array $query = []): Response
    {
        $url = $this->restBase . '/posts' . ($query === [] ? '' : '?' . http_build_query($query));

        return $this->authorized()->get($url);
    }

    public function get(int $id): Response
    {
        return $this->authorized()->get($this->restBase . '/posts/' . $id);
    }

    public function create(string $title, string $content, string $status = 'draft'): Response
    {
        return $this->authorized()->post($this->restBase . '/posts', [], \Ws\Http\Body::json([
            'title'   => $title,
            'content' => $content,
            'status'  => $status,
        ]));
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): Response
    {
        return $this->authorized()->post($this->restBase . '/posts/' . $id, [], \Ws\Http\Body::json($fields));
    }

    public function delete(int $id): Response
    {
        return $this->authorized()->delete($this->restBase . '/posts/' . $id);
    }

    private function authorized(): Request
    {
        $auth = $this->authConfig;

        return $this->http->withOptions(static function (RequestOptions $o) use ($auth): RequestOptions {
            return $o->withAuth($auth['user'], $auth['appPassword']);
        });
    }
}
