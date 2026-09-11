<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * 请求配置值对象(design/11 §4)。
 *
 * 全部配置经 wither 方法设置:每个 with* 返回克隆后的新实例,原实例不变(不可变风格)。
 * 读取器同名去掉 with 前缀。
 */
final class RequestOptions
{
    /** @var int|null 秒级超时;null = 未显式设置 */
    private $timeout;

    /** @var int|null 毫秒级超时(优先于 timeout) */
    private $timeoutMs;

    /** @var bool */
    private $verifyPeer = true;

    /** @var bool */
    private $verifyHost = true;

    /** @var string|null CA 证书包路径;null = 系统 CA */
    private $caBundle;

    /** @var array{0: bool, 1: int, 2: int} json_decode 参数 [assoc, depth, options] */
    private $jsonOpts = [false, 512, 0];

    /** @var HeaderBag 默认请求头 */
    private $defaultHeaders;

    /** @var string|null cookie 字符串 */
    private $cookie;

    /** @var string|null cookie 文件路径(FILE+JAR 同路径) */
    private $cookieFile;

    /** @var array{user: string, pass: string, method: int}|null */
    private $auth;

    /** @var array{address: string, port: int, type: int, tunnel: bool, auth: array|null}|null */
    private $proxy;

    /** @var array<int, mixed> 用户 cURL 选项 */
    private $curlOpts = [];

    /** @var int 最大重定向次数 */
    private $maxRedirects = 10;

    public function __construct()
    {
        $this->defaultHeaders = new HeaderBag();
        // 内置默认超时 30s(design/11 §1.6,修复旧版无限等待)
        $this->timeout = 30;
    }

    // ---------- 超时 ----------

    public function withTimeout(int $seconds): self
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('timeout must be positive seconds');
        }

        return $this->mutate(static function (self $clone) use ($seconds): void {
            $clone->timeout = $seconds;
        });
    }

    public function withTimeoutMs(int $milliseconds): self
    {
        if ($milliseconds <= 0) {
            throw new \InvalidArgumentException('timeoutMs must be positive milliseconds');
        }

        return $this->mutate(static function (self $clone) use ($milliseconds): void {
            $clone->timeoutMs = $milliseconds;
        });
    }

    public function timeout(): int
    {
        return $this->timeout ?? 30;
    }

    public function timeoutMs(): ?int
    {
        return $this->timeoutMs;
    }

    // ---------- SSL ----------

    public function withVerifyPeer(bool $enabled): self
    {
        return $this->mutate(static function (self $clone) use ($enabled): void {
            $clone->verifyPeer = $enabled;
        });
    }

    public function withVerifyHost(bool $enabled): self
    {
        return $this->mutate(static function (self $clone) use ($enabled): void {
            $clone->verifyHost = $enabled;
        });
    }

    public function withCaBundle(string $path): self
    {
        return $this->mutate(static function (self $clone) use ($path): void {
            $clone->caBundle = $path;
        });
    }

    public function verifyPeer(): bool
    {
        return $this->verifyPeer;
    }

    /** @return bool 语义布尔;组装 cURL 时映射为 0/2(design/11 §1.6) */
    public function verifyHost(): bool
    {
        return $this->verifyHost;
    }

    public function caBundle(): ?string
    {
        return $this->caBundle;
    }

    // ---------- JSON ----------

    public function withJsonOpts(bool $assoc, int $depth = 512, int $options = 0): self
    {
        return $this->mutate(static function (self $clone) use ($assoc, $depth, $options): void {
            $clone->jsonOpts = [$assoc, $depth, $options];
        });
    }

    /** @return array{0: bool, 1: int, 2: int} */
    public function jsonOpts(): array
    {
        return $this->jsonOpts;
    }

    // ---------- 默认请求头 ----------

    /** @param array<string, string> $headers 批量合并(同名覆盖) */
    public function withDefaultHeaders(array $headers): self
    {
        return $this->mutate(static function (self $clone) use ($headers): void {
            foreach ($headers as $name => $value) {
                $clone->defaultHeaders->set($name, (string) $value);
            }
        });
    }

    public function withDefaultHeader(string $name, string $value): self
    {
        return $this->withDefaultHeaders([$name => $value]);
    }

    public function withoutDefaultHeaders(): self
    {
        return $this->mutate(static function (self $clone): void {
            $clone->defaultHeaders = new HeaderBag();
        });
    }

    public function defaultHeaders(): HeaderBag
    {
        return $this->defaultHeaders;
    }

    // ---------- Cookie ----------

    public function withCookie(string $cookieString): self
    {
        return $this->mutate(static function (self $clone) use ($cookieString): void {
            $clone->cookie = $cookieString;
        });
    }

    public function withCookieFile(string $path): self
    {
        return $this->mutate(static function (self $clone) use ($path): void {
            $clone->cookieFile = $path;
        });
    }

    public function cookie(): ?string
    {
        return $this->cookie;
    }

    public function cookieFile(): ?string
    {
        return $this->cookieFile;
    }

    // ---------- 认证 ----------

    public function withAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self
    {
        return $this->mutate(static function (self $clone) use ($user, $pass, $method): void {
            $clone->auth = ['user' => $user, 'pass' => $pass, 'method' => $method];
        });
    }

    /** @return array{user: string, pass: string, method: int}|null */
    public function auth(): ?array
    {
        return $this->auth;
    }

    // ---------- 代理 ----------

    public function withProxy(string $address, int $port, int $type = CURLPROXY_HTTP, bool $tunnel = false): self
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('proxy port must be in [1, 65535], got %d', $port));
        }

        return $this->mutate(static function (self $clone) use ($address, $port, $type, $tunnel): void {
            $clone->proxy = [
                'address' => $address,
                'port'    => $port,
                'type'    => $type,
                'tunnel'  => $tunnel,
                'auth'    => $clone->proxy['auth'] ?? null,
            ];
        });
    }

    public function withProxyAuth(string $user, string $pass, int $method = CURLAUTH_BASIC): self
    {
        return $this->mutate(static function (self $clone) use ($user, $pass, $method): void {
            $base = $clone->proxy ?? ['address' => '', 'port' => 80, 'type' => CURLPROXY_HTTP, 'tunnel' => false];
            $base['auth'] = ['user' => $user, 'pass' => $pass, 'method' => $method];
            $clone->proxy = $base;
        });
    }

    /** @return array{address: string, port: int, type: int, tunnel: bool, auth: array|null}|null */
    public function proxy(): ?array
    {
        return $this->proxy;
    }

    // ---------- cURL 透传选项 ----------

    /** @param array<int, mixed> $opts */
    public function withCurlOpts(array $opts): self
    {
        return $this->mutate(static function (self $clone) use ($opts): void {
            foreach ($opts as $option => $value) {
                $clone->curlOpts[$option] = $value;
            }
        });
    }

    public function withCurlOpt(int $option, $value): self
    {
        return $this->withCurlOpts([$option => $value]);
    }

    public function withoutCurlOpts(): self
    {
        return $this->mutate(static function (self $clone): void {
            $clone->curlOpts = [];
        });
    }

    /** @return array<int, mixed> */
    public function curlOpts(): array
    {
        return $this->curlOpts;
    }

    // ---------- 重定向 ----------

    public function withMaxRedirects(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('maxRedirects must be >= 0');
        }

        return $this->mutate(static function (self $clone) use ($count): void {
            $clone->maxRedirects = $count;
        });
    }

    public function maxRedirects(): int
    {
        return $this->maxRedirects;
    }

    // ---------- 内部 ----------

    /**
     * wither 基础设施:浅克隆 + 变更回调。
     * HeaderBag 为可变对象,克隆时需一并复制以保证 defaultHeaders 的不可变性。
     */
    private function mutate(callable $fn): self
    {
        $clone = clone $this;
        $clone->defaultHeaders = clone $this->defaultHeaders;

        $fn($clone);

        return $clone;
    }
}
