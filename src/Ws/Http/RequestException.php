<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * cURL 传输层异常(连接失败 / DNS / 超时 / SSL 握手等)。
 *
 * 仅在传输层失败时抛出;HTTP 4xx/5xx 不抛,由调用方或断言层处理(design/11 §7)。
 *
 * @immutable 属性构造后不可变(PHP 7.4 无 readonly,按约定只读)
 */
class RequestException extends Exception
{
    /** @var int cURL errno(如 28=超时,6=DNS) */
    private $curlErrno;

    /** @var string cURL 原始错误描述 */
    private $curlError;

    /** @var string 请求 URL */
    private $url;

    /** @var string 请求方法 */
    private $method;

    public function __construct(int $curlErrno, string $curlError, string $method, string $url)
    {
        $this->curlErrno = $curlErrno;
        $this->curlError = $curlError;
        $this->url = $url;
        $this->method = $method;

        // 消息模板: cURL error {errno}: {error} [{method} {url}] (design/11 §7)
        parent::__construct(
            sprintf('cURL error %d: %s [%s %s]', $curlErrno, $curlError, $method, $url),
            101
        );
    }

    public function curlErrno(): int
    {
        return $this->curlErrno;
    }

    public function curlError(): string
    {
        return $this->curlError;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function method(): string
    {
        return $this->method;
    }
}
