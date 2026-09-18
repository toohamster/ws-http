<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\HttpStep;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Automated\StepResult;
use Ws\Http\Request;
use Ws\Http\RequestOptions;
use Ws\Http\Response;

/**
 * S9 引擎测试替身:可控 Request 工厂(不发网络,按脚本产出预制 Response)。
 *
 * 与 FakeCurlRequest 同规范:专属类、静态捕获、可复用。
 */
final class FakeRequestFactory implements \Ws\Http\Contract\RequestFactoryInterface
{
    /** @var array<int, Response> 按 create 调用序号出队的响应队列 */
    public array $responses = [];

    /** @var array<int, array{url: string, method: string, body: mixed, headers: array}> 捕获的请求 */
    public array $sent = [];

    /** @var array<int, RequestOptions> 按 create 调用序号捕获的选项(redirect/timeout 断言用) */
    public array $capturedOptions = [];

    private int $index = 0;

    public function queue(Response $response): void
    {
        $this->responses[] = $response;
    }

    public function create(RequestOptions $options): Request
    {
        // 返回一个受控 Request:executeCurl 直接返回队列中的响应
        $response = $this->responses[$this->index] ?? $this->defaultResponse();
        $this->capturedOptions[$this->index] = $options;
        $this->index++;

        return new SealedRequest($response, $this, $this->index - 1);
    }

    /**
     * 记录一次实际发送(由 SealedRequest 回调)。
     *
     * @param mixed $body
     * @param array<string, string> $headers
     */
    public function record(string $method, string $url, $body, array $headers): void
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
    }

    /**
     * 最近一次发送的请求。
     *
     * @return array{method: string, url: string, body: mixed, headers: array}|null
     */
    public function lastSent(): ?array
    {
        return $this->sent === [] ? null : $this->sent[\count($this->sent) - 1];
    }

    private function defaultResponse(): Response
    {
        return new Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '{}', '');
    }
}

/**
 * 密封 Request:send() 直接返回预制 Response 并向工厂登记。
 */
final class SealedRequest extends Request
{
    /** @var Response */
    private $response;

    /** @var FakeRequestFactory */
    private $factory;

    /** @var int 本实例对应的请求序号 */
    private $seq;

    public function __construct(Response $response, FakeRequestFactory $factory, int $seq)
    {
        parent::__construct();
        $this->response = $response;
        $this->factory = $factory;
        $this->seq = $seq;
    }

    public function send(string $method, string $url, $body = null, array $headers = []): Response
    {
        $this->factory->record($method, $url, $body, $headers);

        return $this->response;
    }
}
