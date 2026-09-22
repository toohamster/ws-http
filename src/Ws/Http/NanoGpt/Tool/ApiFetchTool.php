<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Request;
use Ws\Http\Response;

/**
 * 数据提取(design/25 §4.5,消费心智):GET + JSONPath 提取,输出紧凑 JSON 结果集。
 *
 * 与 ApiTestTool(验证)正交——本工具回答"数据拿来用"(天气/文章/接口间搬运)。
 * 提取复用既有引擎(ExpressionEvaluator/design-13;伪 Response 模式先例:Runner::runPauseStep,design/22)。
 */
final class ApiFetchTool extends HttpToolBase
{
    public function __construct(array $allowHosts, ?Request $http = null, int $maxBody = 2048, bool $allowPrivateHosts = false)
    {
        parent::__construct($allowHosts, $http, $maxBody, $allowPrivateHosts);
    }

    public function name(): string
    {
        return 'api_fetch';
    }

    public function description(): string
    {
        return 'Fetch an allowed API endpoint and extract data: give JSONPath expressions, get a compact JSON result set (omit extract to get the raw body).';
    }

    protected function httpMethod(): string
    {
        return 'GET';
    }

    public function jsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url'     => ['type' => 'string', 'description' => 'Absolute http(s) URL'],
                'extract' => ['type' => 'array', 'description' => 'JSONPath expressions to extract (e.g. ["$.data.temp", "$.city"]); omitted = raw body excerpt'],
            ],
            'required'   => ['url'],
        ];
    }

    /**
     * 输出契约(design/25 §4.5 评审钉死):
     * - extract 空/缺省 → body 原样截断(退化形态 = ApiGetTool);
     * - extract 有 → 紧凑 JSON 结果集 {"items": {"$.path": value, ...}}(声明式取数,防上下文膨胀);
     * - 提取无匹配的 path → "(no match)" 占位(结构可预期)。
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string
    {
        if ($this->allowHosts === []) {
            return 'error: api_fetch is disabled (no allowed hosts configured)';
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

        $extract = $args['extract'] ?? null;
        if (!\is_array($extract) || $extract === []) {
            return $this->send($url); // 退化形态
        }

        $http = $this->http ?? new Request();
        try {
            $response = $http->send($this->method, $url, null, []);
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }

        if ($response->body === false) {
            return sprintf('status: %d\nerror: response body is not valid JSON (extract requires JSON)', $response->code);
        }

        $items = [];
        $evaluator = new ExpressionEvaluator();
        foreach ($extract as $path) {
            $path = (string) $path;
            $evaluated = $evaluator->evaluate($response->body, $path);
            $items[$path] = $evaluated->isEmpty()
                ? '(no match)'
                : $evaluated->first();
        }

        return sprintf(
            "status: %d\n%s",
            $response->code,
            (string) json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
