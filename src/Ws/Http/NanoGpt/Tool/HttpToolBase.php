<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Tool;

use Ws\Http\Method;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\ToolInterface;
use Ws\Http\Request;

/**
 * 网络工具抽象基类(design/25 §4.5):执行体 = core Request。
 *
 * 骨架 = method(子类写死)+ host 白名单(构造注入);槽位 = path/query;
 * body 仅 json_file 形态(防巨型 inline)。
 *
 * 防线(design/25 §5.2):
 * - host 白名单:主要防线(精确匹配;空表 = 禁用);
 * - 内网策略:allowPrivateHosts 可配置(默认 false)——配置错误的最后防线,
 *   企业内网 MCP/内部服务场景显式开启;白名单含内网条目且未放行 → 构造期 613;
 * - 重定向封堵:网络族默认 maxRedirects=0(HTTP 特有 SSRF 通道);
 * - 输出截断:与命令族同约定。
 *
 * 诚实定位:尽力保证(DNS rebinding 等高级绕过不承诺绝对,与 ExecTool 同级)。
 */
abstract class HttpToolBase implements ToolInterface
{
    /** @var string[] host 白名单(精确匹配,大小写不敏感;空表 = 禁用) */
    protected $allowHosts;

    /** @var bool 内网地址放行(默认 false) */
    protected $allowPrivateHosts;

    /** @var Request|null 注入(测试替身);null 时新建 */
    protected $http;

    /** @var int body 摘录最大长度 */
    protected $maxBody;

    /** @var string 骨架 method(子类写死) */
    protected $method;

    /** @var string[] 内网主机名集合(allowPrivateHosts=false 时拒绝;IP 段由 filter_var 判定,不在此列) */
    private const PRIVATE_HOSTS = ['localhost'];

    /**
     * @param string[] $allowHosts
     * @param string[]|null $privateHostPatterns 追加的内网主机名(测试可注入);null = 默认集合
     */
    public function __construct(
        array $allowHosts,
        ?Request $http = null,
        int $maxBody = 2048,
        bool $allowPrivateHosts = false,
        ?array $privateHostPatterns = null
    ) {
        $this->allowHosts = array_map('strtolower', array_values($allowHosts));
        $this->allowPrivateHosts = $allowPrivateHosts;
        $this->http = $http;
        $this->maxBody = $maxBody;
        $this->method = $this->httpMethod();

        // 构造期校验:method 合法
        $allMethods = (new \ReflectionClass(Method::class))->getConstants();
        if (!\in_array($this->method, $allMethods, true)) {
            throw new AgentException(
                sprintf('HttpTool "%s" has invalid method "%s"', $this->name(), $this->method),
                613
            );
        }

        // 构造期对账:白名单含内网条目且未放行 → 613(配置错误早暴露)
        if (!$this->allowPrivateHosts) {
            $private = $privateHostPatterns ?? self::PRIVATE_HOSTS;
            foreach ($this->allowHosts as $host) {
                if ($this->isPrivateHost($host, $private)) {
                    throw new AgentException(
                        sprintf('HttpTool "%s": allowHosts contains private host "%s" but allowPrivateHosts=false (set allowPrivateHosts=true for intranet services)', $this->name(), $host),
                        613
                    );
                }
            }
        }
    }

    /** 骨架 method(子类写死,如 'GET') */
    abstract protected function httpMethod(): string;

    /**
     * URL 拼装:host 白名单校验 → host + path(+query 空不带 ?)。
     *
     * @param array<string, mixed> $args
     */
    protected function buildUrl(array $args): string
    {
        if ($this->allowHosts === []) {
            return '';
        }

        $path = ltrim((string) ($args['path'] ?? ''), '/');
        $query = (string) ($args['query'] ?? '');

        // query 槽:直接拼(模型给 k=v&k2=v2 形态;空则不带 ?)
        $suffix = $query !== '' ? '?' . $query : '';

        return 'https://' . $this->allowHosts[0] . '/' . $path . $suffix;
    }

    /**
     * host 白名单 + 内网策略校验(执行期;host 来自白名单本身,此校验主要保护子类自定义 URL 场景)。
     */
    protected function assertHostAllowed(string $host): void
    {
        if ($this->allowHosts === []) {
            throw new AgentException('http tool is disabled (no allowed hosts configured)', 613);
        }

        $host = strtolower($host);
        if (!\in_array($host, $this->allowHosts, true)) {
            throw new AgentException(sprintf('host "%s" is not in the allowed list', $host), 613);
        }

        if (!$this->allowPrivateHosts && $this->isPrivateHost($host, self::PRIVATE_HOSTS)) {
            throw new AgentException(sprintf('private host "%s" is not allowed (set allowPrivateHosts=true)', $host), 613);
        }
    }

    /**
     * 发送并格式化输出(status + body 截断;与 HttpGetTool 既有形态一致)。
     *
     * @param array<string, string> $headers
     * @param mixed $body
     */
    protected function send(string $url, array $headers = [], $body = null): string
    {
        $http = $this->http ?? new Request();
        // 重定向封堵(网络族默认;HTTP 特有 SSRF 通道)
        $http = $http->withOptions(function (\Ws\Http\RequestOptions $o): \Ws\Http\RequestOptions {
            return $o->withMaxRedirects(0);
        });

        try {
            $response = $http->send($this->method, $url, $body, $headers);
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }

        return sprintf(
            "status: %d\nbody: %s",
            $response->code,
            mb_substr($response->rawBody, 0, $this->maxBody)
        );
    }

    /**
     * @param string $host
     * @param string[] $patterns
     */
    private function isPrivateHost(string $host, array $patterns): bool
    {
        // IP 段判定(简表;完整 CIDR 判定属"尽力保证"定位)
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        foreach ($patterns as $pattern) {
            if ($host === $pattern || strpos($host, $pattern . '.') === 0 || strpos($host, '.' . $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
