<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Core;

use Ws\Http\Request;
use Ws\Http\RequestOptions;

/**
 * 测试专用 Request:不发真实网络。
 *
 * - executeCurl 返回预设响应;
 * - 捕获的 cURL 选项存入 public static $captured(每个实例写入前自增序号隔离,测试可按序取)。
 *
 * 构造签名兼容父类:new static($options)(withOptions 派生时)多余参数被忽略。
 */
final class FakeCurlRequest extends Request
{
    /** @var array<int, array<int, mixed>> 最近一次 executeCurl 收到的选项(按实例序号) */
    public static array $captured = [];

    /** @var int 实例序号(静态自增),测试经同序号读捕获 */
    private static int $seq = 0;

    /** @var int 本实例序号 */
    private int $instanceSeq;

    /** @var string */
    private $raw;

    /** @var array<string, mixed> */
    private $info;

    /**
     * @param array<string, mixed> $info curl_getinfo 模拟值
     */
    public function __construct(string $raw, array $info = [])
    {
        // 父类构造可传 ?RequestOptions;这里自建默认,再由测试用 withOptions 派生
        parent::__construct();
        $this->raw = $raw;
        $this->info = $info;
        $this->instanceSeq = ++self::$seq;
    }

    protected function executeCurl(array $options): array
    {
        self::$captured[$this->instanceSeq] = $options;

        return [
            $this->raw,
            $this->info + ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1],
        ];
    }

    /**
     * 本实例最近一次执行捕获到的 cURL 选项。
     *
     * @return array<int, mixed>
     */
    public function capturedOptions(): array
    {
        return self::$captured[$this->instanceSeq] ?? [];
    }

    /**
     * withOptions 派生的新实例继承本实例的 raw/info 与序号(clone 语义自动保持)。
     * 父类 withOptions 用 clone 实现,无需额外处理;
     * 此方法仅供测试直接注入 RequestOptions。
     */
    public function withFixedOptions(RequestOptions $options): self
    {
        $clone = clone $this;

        $prop = new \ReflectionProperty(Request::class, 'options');
        $prop->setAccessible(true);
        $prop->setValue($clone, $options);

        return $clone;
    }
}
