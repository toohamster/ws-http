<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * HTTP 响应对象(design/11 §3)。
 *
 * $body 仅当 Content-Type 含 application/json 且 json_decode 成功时填充(遵循 jsonOpts);
 * 解析失败时 $body = false 且 $jsonError 记录 [errno, message]。
 */
final class Response
{
    /** @var int HTTP 状态码 */
    public $code;

    /** @var string 状态行,如 "HTTP/1.1 200 OK" */
    public $statusLine;

    /** @var HeaderBag 响应头(大小写不敏感) */
    public $headers;

    /** @var string 原始响应体 */
    public $rawBody;

    /** @var array<string, mixed> curl_getinfo 全量 */
    public $curlInfo;

    /** @var mixed 解析后 body;非 JSON 或解析失败为 false(JSON null 字面量例外,见 jsonError) */
    public $body = false;

    /** @var array{0: int, 1: string}|null JSON 解析失败时的 [errno, message];成功或非 JSON 内容为 null */
    public $jsonError;

    /**
     * @param array<string, mixed> $curlInfo
     * @param array{0: bool, 1: int, 2: int} $jsonOpts [assoc, depth, options]
     */
    public function __construct(array $curlInfo, string $rawBody, string $rawHeaders, array $jsonOpts = [false, 512, 0])
    {
        $this->code = (int) ($curlInfo['http_code'] ?? 0);
        $this->curlInfo = $curlInfo;
        $this->rawBody = $rawBody;

        $this->headers = HeaderBag::fromRawHeaders($rawHeaders);
        $this->statusLine = $this->extractStatusLine($rawHeaders);

        $contentType = $this->headers->get('Content-Type');

        if ($contentType !== null && stripos($contentType, 'application/json') !== false) {
            [$assoc, $depth, $options] = $jsonOpts;

            $decoded = json_decode($rawBody, $assoc, $depth, $options);

            if (json_last_error() === JSON_ERROR_NONE) {
                $this->body = $decoded;
                $this->jsonError = null;
            } else {
                $this->body = false;
                $this->jsonError = [json_last_error(), json_last_error_msg()];
            }
        }
    }

    /**
     * 2xx 判定(含 200/299 边界)。
     */
    public function isOk(): bool
    {
        return $this->code >= 200 && $this->code < 300;
    }

    /**
     * 大小写不敏感取响应头;缺失返回 null。
     */
    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }

    /**
     * 请求总耗时(秒,curl_info['total_time']),断言器使用。
     */
    public function totalTime(): float
    {
        return (float) ($this->curlInfo['total_time'] ?? 0.0);
    }

    /**
     * 从原始头块提取状态行(首行,如 "HTTP/1.1 200 OK")。
     */
    private function extractStatusLine(string $rawHeaders): string
    {
        $first = strtok($rawHeaders, "\r\n");

        return $first === false ? '' : $first;
    }
}
