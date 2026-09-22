<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\Body;
use Ws\Http\Request;

/**
 * POST JSON(design/25 §4.5):提交数据/触发 webhook。
 *
 * body 仅 json_file 形态(槽 json_file,从沙箱区读文件内容原样发送)——防巨型 inline 参数;
 * 文件经 write_file 先写到 .work/.runtime(json_file 路径不做 Sandbox 校验:POST 目标域
 * 才是安全边界;读文件即模型已可通过 read_file 读到,无越权放大)。
 */
final class ApiJsonPostTool extends HttpToolBase
{
    public function __construct(array $allowHosts, ?Request $http = null, int $maxBody = 2048, bool $allowPrivateHosts = false)
    {
        parent::__construct($allowHosts, $http, $maxBody, $allowPrivateHosts);
    }

    public function name(): string
    {
        return 'api_json_post';
    }

    public function description(): string
    {
        return 'POST a JSON body (read from a file) to an allowed API endpoint. Write the JSON with write_file first, then reference its path.';
    }

    protected function httpMethod(): string
    {
        return 'POST';
    }

    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url'      => ['type' => 'string', 'description' => 'Absolute http(s) URL'],
                'json_file' => ['type' => 'string', 'description' => 'Path to JSON file (write it first via write_file)'],
            ],
            'required'   => ['url', 'json_file'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        if ($this->allowHosts === []) {
            return 'error: api_json_post is disabled (no allowed hosts configured)';
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

        $jsonFile = (string) ($args['json_file'] ?? '');
        $raw = @\file_get_contents($jsonFile);
        if ($raw === false) {
            return sprintf('error: cannot read json_file "%s" (write it via write_file first)', $jsonFile);
        }

        if (json_decode($raw) === null && json_last_error() !== JSON_ERROR_NONE) {
            return sprintf('error: json_file "%s" is not valid JSON', $jsonFile);
        }

        return $this->send($url, ['Content-Type' => 'application/json'], Body::raw($raw, 'application/json'));
    }
}
