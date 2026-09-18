<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\Body;
use Ws\Http\NanoGpt\ToolInterface;
use Ws\Http\Request;

/**
 * GET JSON API(design/21 §4.1):"读网络接口"最小形态——域名白名单,空表 = 禁用。
 *
 * 复杂 HTTP 需求(POST/认证/分页)应由使用者自定义 Tool 实现(扩展点演示)。
 */
final class HttpGetTool implements ToolInterface
{
    /** @var string[] 允许的域名;空表 = 禁用 */
    private $allowHosts;

    /** @var Request|null 注入(测试替身);null 时用共享实例 */
    private $http;

    /** @var int body 摘录最大长度 */
    private $maxBody;

    /**
     * @param string[] $allowHosts
     */
    public function __construct(array $allowHosts = [], ?Request $http = null, int $maxBody = 2048)
    {
        $this->allowHosts = array_values($allowHosts);
        $this->http = $http;
        $this->maxBody = $maxBody;
    }

    public function name(): string
    {
        return 'http_get';
    }

    public function description(): string
    {
        return 'Fetch a JSON API endpoint via HTTP GET and return the status code with a body excerpt.';
    }

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

        // 白名单精确匹配主机名(含子域须显式列出)
        if (!\in_array(strtolower($host), array_map('strtolower', $this->allowHosts), true)) {
            return sprintf('error: host "%s" is not in the allowed list', $host);
        }

        $http = $this->http ?? new Request();
        try {
            $response = $http->get($url);
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }

        return sprintf(
            "status: %d\nbody: %s",
            $response->code,
            mb_substr($response->rawBody, 0, $this->maxBody)
        );
    }
}
