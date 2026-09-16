<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * cURL HTTP 引擎(design/11 §1)。
 *
 * 职责:持有 RequestOptions,组装 cURL 会话并执行,产出 Response。
 * 所有快捷方法(get/post/...)最终都经 send() 这一个咽喉点。
 */
class Request
{
    /** @var RequestOptions 当前配置(不可变对象,withOptions 派生) */
    private $options;

    public function __construct(?RequestOptions $options = null)
    {
        $this->options = $options ?? new RequestOptions();
    }

    /**
     * 派生新 Request:wither 回调修改配置副本,原实例不变。
     *
     * @param callable(RequestOptions): RequestOptions $mutator
     */
    public function withOptions(callable $mutator): self
    {
        $clone = clone $this;
        $clone->options = $mutator($this->options);

        return $clone;
    }

    public function options(): RequestOptions
    {
        return $this->options;
    }

    // ---------- 快捷方法(语义表见 design/11 §1.3) ----------

    /**
     * @param array|object|null $parameters 拍平进 query string
     */
    public function get(string $url, array $headers = [], $parameters = null): Response
    {
        return $this->send(Method::GET, $url, $parameters, $headers);
    }

    public function head(string $url, array $headers = [], $parameters = null): Response
    {
        return $this->send(Method::HEAD, $url, $parameters, $headers);
    }

    public function options_(string $url, array $headers = [], $parameters = null): Response
    {
        return $this->send(Method::OPTIONS, $url, $parameters, $headers);
    }

    /**
     * @param mixed $body 请求体
     */
    public function post(string $url, array $headers = [], $body = null): Response
    {
        return $this->send(Method::POST, $url, $body, $headers);
    }

    public function put(string $url, array $headers = [], $body = null): Response
    {
        return $this->send(Method::PUT, $url, $body, $headers);
    }

    public function patch(string $url, array $headers = [], $body = null): Response
    {
        return $this->send(Method::PATCH, $url, $body, $headers);
    }

    public function delete(string $url, array $headers = [], $body = null): Response
    {
        return $this->send(Method::DELETE, $url, $body, $headers);
    }

    public function trace(string $url, array $headers = [], $body = null): Response
    {
        return $this->send(Method::TRACE, $url, $body, $headers);
    }

    /**
     * 任意方法(标准/自定义,配合 Method 常量)。
     *
     * @param mixed $body
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, $body = null, array $headers = []): Response
    {
        $method = strtoupper($method);
        $options = $this->buildCurlOptions($method, $url, $body, $headers);
        [$rawResponse, $info] = $this->executeCurl($options);

        return $this->buildResponse($rawResponse, $info, $this->options->jsonOpts(), $method, $url);
    }

    // ---------- cURL 组装(内部,可测) ----------

    /**
     * 组装完整 cURL 选项(design/11 §1.4 流程 1–3)。
     *
     * @param mixed $body
     * @param array<string, string> $headers
     * @return array<int, mixed>
     */
    protected function buildCurlOptions(string $method, string $url, $body, array $headers): array
    {
        $curlUrl = $url;
        $postFields = null;

        if (MethodHelper::isBodyAllowed($method)) {
            // 体方法:body 归一化(PreparedBody → content + Content-Type 头)
            if ($body instanceof PreparedBody) {
                $headers = $this->applyContentType($body->contentType, $headers);
                $body = $body->content;
            }
            // cURL 只对 POST 自动补 Content-Type;PUT/PATCH/DELETE 的字符串 body
            // 必须显式给类型,否则部分服务端拒收 body(实测 httpbin 丢 body)
            if (\is_string($body) && !$this->hasContentType($headers)) {
                $headers = $this->applyContentType('text/plain', $headers);
            }
            $postFields = $body;
        } else {
            // GET/HEAD/OPTIONS:数组/对象参数拍平进 query
            if (\is_array($body) || \is_object($body)) {
                $query = http_build_query(UrlKit::buildHttpQuery($body));
                $curlUrl = $url . (strpos($url, '?') !== false ? '&' : '?') . $query;
            } elseif ($body !== null) {
                throw new \InvalidArgumentException(
                    sprintf('%s does not accept a body parameter, pass null or an array', $method)
                );
            }
        }

        $base = [
            CURLOPT_URL            => UrlKit::encodeUrl($curlUrl),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => $this->options->maxRedirects(),
            CURLOPT_HEADER         => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => $this->options->verifyPeer(),
            CURLOPT_SSL_VERIFYHOST => $this->options->verifyHost() ? 2 : 0,
            CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
            CURLOPT_TIMEOUT        => $this->options->timeout(),
        ];

        if ($method === Method::POST) {
            $base[CURLOPT_POST] = true;
            $base[CURLOPT_POSTFIELDS] = $postFields;
        } elseif ($method !== Method::GET) {
            $base[CURLOPT_CUSTOMREQUEST] = $method;
            if ($postFields !== null) {
                $base[CURLOPT_POSTFIELDS] = $postFields;
            }
        }

        if ($this->options->verifyPeer() && $this->options->caBundle() !== null) {
            $base[CURLOPT_CAINFO] = $this->options->caBundle();
        }

        if ($this->options->timeoutMs() !== null) {
            if ($this->options->timeoutMs() < 1000) {
                // 规避 PHP timeout-ms 的 signal 问题(laruence.com/2014/01/21/2939.html)
                $base[CURLOPT_NOSIGNAL] = 1;
            }
            $base[CURLOPT_TIMEOUT_MS] = $this->options->timeoutMs();
        }

        if ($this->options->cookie() !== null) {
            $base[CURLOPT_COOKIE] = $this->options->cookie();
        }

        if ($this->options->cookieFile() !== null) {
            $base[CURLOPT_COOKIEFILE] = $this->options->cookieFile();
            $base[CURLOPT_COOKIEJAR] = $this->options->cookieFile();
        }

        $auth = $this->options->auth();
        if ($auth !== null && $auth['user'] !== '') {
            $base[CURLOPT_HTTPAUTH] = $auth['method'];
            $base[CURLOPT_USERPWD] = $auth['user'] . ':' . $auth['pass'];
        }

        $proxy = $this->options->proxy();
        if ($proxy !== null && $proxy['address'] !== '') {
            $base[CURLOPT_PROXY] = $proxy['address'];
            $base[CURLOPT_PROXYPORT] = $proxy['port'];
            $base[CURLOPT_PROXYTYPE] = $proxy['type'];
            $base[CURLOPT_HTTPPROXYTUNNEL] = $proxy['tunnel'];
            if ($proxy['auth'] !== null) {
                $base[CURLOPT_PROXYAUTH] = $proxy['auth']['method'];
                $base[CURLOPT_PROXYUSERPWD] = $proxy['auth']['user'] . ':' . $proxy['auth']['pass'];
            }
        }

        // 优先级:用户 curlOpt > 上面的全部组装(design/11 §1.6)
        return $this->options->curlOpts() + $base;
    }

    /**
     * cURL 执行缝隙:返回 [rawResponse, curlInfo];传输失败抛 RequestException。
     * 单测经 override 注入假响应,不发真实网络。
     *
     * @param array<int, mixed> $options
     * @return array{0: string|false, 1: array<string, mixed>}
     */
    protected function executeCurl(array $options): array
    {
        // curl_init 成功返回 resource(PHP 7.4)/ CurlHandle(PHP 8);false=初始化失败
        $handle = curl_init();
        if ($handle === false) {
            // curl_init 失败(极端资源耗尽场景);按传输错误处理
            throw new RequestException(0, 'curl_init() failed', 'GET', (string) ($options[CURLOPT_URL] ?? ''));
        }
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        /** @var array<string, mixed> $info(curl_getinfo 在 exec 后恒返回数组) */
        $info = curl_getinfo($handle) ?: [];
        curl_close($handle);

        if ($errno !== 0) {
            $method = $options[CURLOPT_CUSTOMREQUEST] ?? (isset($options[CURLOPT_POST]) ? 'POST' : 'GET');

            throw new RequestException($errno, $error, (string) $method, (string) $options[CURLOPT_URL]);
        }

        return [$response === false ? '' : (string) $response, $info];
    }

    /**
     * 按 header_size 切分并构造 Response(design/11 §1.4 流程 6–7)。
     *
     * @param array<string, mixed> $info
     * @param array{0: bool, 1: int, 2: int} $jsonOpts
     */
    protected function buildResponse($rawResponse, array $info, array $jsonOpts, string $method, string $url): Response
    {
        $raw = (string) $rawResponse;
        $headerSize = (int) ($info['header_size'] ?? 0);

        $rawHeaders = $headerSize > 0 ? substr($raw, 0, $headerSize) : '';
        $rawBody = $headerSize > 0 ? substr($raw, $headerSize) : $raw;

        return new Response($info, $rawBody, $rawHeaders, $jsonOpts);
    }

    /**
     * 合并默认头 + 本次头,输出小写名头行(设计 11 §1.6)。
     * 未显式设置时补 user-agent 与空 expect。
     *
     * @param array<string, string> $headers
     * @return string[]
     */
    protected function formatHeaders(array $headers): array
    {
        $bag = clone $this->options->defaultHeaders();
        foreach ($headers as $name => $value) {
            $bag->set($name, (string) $value);
        }

        if (!$bag->has('user-agent')) {
            $bag->set('user-agent', 'ws-http/2.0');
        }
        if (!$bag->has('expect')) {
            $bag->set('expect', '');
        }

        return $bag->toCurlHeaders();
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasContentType(array $headers): bool
    {
        foreach ($headers as $name => $_) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * PreparedBody 的建议 Content-Type 应用规则:用户显式头优先。
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function applyContentType(?string $contentType, array $headers): array
    {
        if ($contentType === null) {
            return $headers;
        }

        foreach ($headers as $name => $_) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                return $headers; // 用户显式指定,优先(design/11 §5)
            }
        }

        $headers['Content-Type'] = $contentType;

        return $headers;
    }
}
