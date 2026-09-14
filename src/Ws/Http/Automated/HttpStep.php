<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * HTTP 请求步骤(design/14 §2.1):resolve 后交由 Runner 组装请求。
 *
 * 字段保持"脚本裸形态"(数组),由 Runner 在执行时 resolve+组装 ——
 * 这样 ScenarioParser 只做校验,替换语义(resolver)与请求组装(runner)各自独立可测。
 */
final class HttpStep implements Step
{
    /** @var string */
    private $id;

    /** @var string */
    private $name;

    /** @var string */
    private $url;

    /** @var string 大写方法 */
    private $method;

    /** @var array<string, string> 请求头(原始值,可含 ${var}) */
    private $headers;

    /** @var array<string, mixed>|null auth 结构(type/user/password/token/value) */
    private $auth;

    /** @var array<string, mixed>|null proxy 结构 */
    private $proxy;

    /** @var array{mode: string, content: mixed}|null body 结构 */
    private $body;

    /** @var array<int, array<string, mixed>> extract 规则(VarRule 裸形态) */
    private $extract;

    /** @var array<int, array<string, mixed>> assertions 规则(AssertRule 裸形态) */
    private $assertions;

    /** @var string|null 步骤级超时覆盖 */
    private $timeout;

    /** @var array<string, true> 运行期提取变量中需标记 secret 的名字(引擎注入,design/15 §2.1.1) */
    private $extractSecrets = [];

    /**
     * @param array<string, string> $headers
     * @param array<int, array<string, mixed>> $extract
     * @param array<int, array<string, mixed>> $assertions
     */
    public function __construct(
        string $id,
        string $name,
        string $url,
        string $method,
        array $headers = [],
        ?array $auth = null,
        ?array $proxy = null,
        ?array $body = null,
        array $extract = [],
        array $assertions = [],
        ?string $timeout = null
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->url = $url;
        $this->method = strtoupper($method);
        $this->headers = $headers;
        $this->auth = $auth;
        $this->proxy = $proxy;
        $this->body = $body;
        $this->extract = $extract;
        $this->assertions = $assertions;
        $this->timeout = $timeout;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return 'http';
    }

    public function url(): string
    {
        return $this->url;
    }

    public function method(): string
    {
        return $this->method;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, mixed>|null */
    public function auth(): ?array
    {
        return $this->auth;
    }

    /** @return array<string, mixed>|null */
    public function proxy(): ?array
    {
        return $this->proxy;
    }

    /** @return array{mode: string, content: mixed}|null */
    public function body(): ?array
    {
        return $this->body;
    }

    /** @return array<int, array<string, mixed>> */
    public function extract(): array
    {
        return $this->extract;
    }

    /** @return array<int, array<string, mixed>> */
    public function assertions(): array
    {
        return $this->assertions;
    }

    public function timeout(): ?string
    {
        return $this->timeout;
    }

    /**
     * 运行期标记某提取变量为 secret(引擎/脚本加载器注入;按变量名绑定,design/15 §2.1.1)。
     */
    public function markExtractSecret(string $varName): void
    {
        $this->extractSecrets[$varName] = true;
    }

    /**
     * @return array<string, true>
     */
    public function extractSecrets(): array
    {
        return $this->extractSecrets;
    }
}
