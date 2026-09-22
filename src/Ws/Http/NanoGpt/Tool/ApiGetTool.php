<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\NanoGpt\ToolInterface;
use Ws\Http\Request;

/**
 * GET 读接口(design/25 §4.5):HttpGetTool 归位迁移为 HttpToolBase 子类(行为不变,测试回归)。
 */
final class ApiGetTool extends HttpToolBase
{
    public function __construct(array $allowHosts, ?Request $http = null, int $maxBody = 2048, bool $allowPrivateHosts = false)
    {
        parent::__construct($allowHosts, $http, $maxBody, $allowPrivateHosts);
    }

    public function name(): string
    {
        return 'http_get';
    }

    public function description(): string
    {
        return 'Fetch an allowed API endpoint via HTTP GET and return the status code with a body excerpt.';
    }

    protected function httpMethod(): string
    {
        return 'GET';
    }

    /**
     * 行为不变迁移:槽位 = url(整串,白名单校验 host)——保持既有 schema,模型侧零感知。
     */
    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Absolute http(s) URL'],
            ],
            'required'   => ['url'],
        ];
    }

    /**
     * 行为不变迁移:槽位 = url(整串,白名单校验 host)——保持既有 schema,模型侧零感知。
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        if ($this->allowHosts === []) {
            return 'error: http_get is disabled (no allowed hosts configured)';
        }

        $url = (string) ($args['url'] ?? '');
        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return 'error: invalid url';
        }

        try {
            $this->assertHostAllowed($host);
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            return 'error: ' . $e->getMessage();
        }

        return $this->send($url);
    }
}
