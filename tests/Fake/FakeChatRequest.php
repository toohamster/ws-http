<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Fake;

use Ws\Http\Request;

/**
 * 状态码序列分发 Request 替身(tests/Fake 规格,design/28 D1)。
 *
 * 模拟对象 = **一条传输链路**,不是单个 Request 实例:core 契约下请求经
 * withOptions(clone) 派生执行,实例只是过客;因此帧序列/帧指针/发送捕获
 * 收进链路态 WireState(独立实名类,见同目录),实例以属性句柄持有——
 * clone 拷贝句柄,克隆内推进帧指针、追加捕获,原对象(测试持有的引用)
 * 全部可见。不使用 static(无全局态、无跨测试污染、无序号簿记)。
 *
 * 覆写点 = executeCurl(protected 引擎缝隙,Request::send() 内部调用,
 * 派生克隆后依然生效)。帧 = [httpCode, rawBody, rawHeaders?];按序分发,
 * 序列耗尽后停在末帧(重试场景:429,429,200 → 两次失败后成功)。
 *
 * 帧未带 headers 时默认补 Content-Type: application/json(Response 仅在
 * application/json 时解析 body,否则 body 恒 false)。
 */
final class FakeChatRequest extends Request
{
    /** @var WireState 链路态(句柄共享,跨 clone 互通) */
    private $wire;

    /**
     * @param array<int, array{0: int, 1: string, 2?: string}> $frames [code, rawBody, rawHeaders?]
     */
    public function __construct(array $frames)
    {
        parent::__construct();
        $this->wire = new WireState($frames);
    }

    protected function executeCurl(array $options): array
    {
        $frame = $this->wire->next();

        $method = $options[CURLOPT_CUSTOMREQUEST] ?? (isset($options[CURLOPT_POST]) ? 'POST' : 'GET');
        $this->wire->capture([
            'method'  => (string) $method,
            'url'     => (string) ($options[CURLOPT_URL] ?? ''),
            'body'    => $options[CURLOPT_POSTFIELDS] ?? null,
            'headers' => $options[CURLOPT_HTTPHEADER] ?? [],
        ]);

        $raw = $frame[1];
        $rawHeaders = $frame[2] ?? sprintf("HTTP/1.1 %d Fake\r\nContent-Type: application/json\r\n", $frame[0]);

        // 模拟 cURL 返回形态:headers+body 连体,header_size 切分(与真实 executeCurl 产出一致)
        return [$rawHeaders . $raw, ['http_code' => $frame[0], 'header_size' => strlen($rawHeaders), 'total_time' => 0.1]];
    }

    /**
     * 已发出的请求记录(链路态,跨 clone 汇聚到同一份)。
     *
     * @return array<int, array{method: string, url: string, body: mixed, headers: array}>
     */
    public function sent(): array
    {
        return $this->wire->captured();
    }

    /**
     * 最近一次发送的请求体(CURLOPT_POSTFIELDS 原样;PreparedBody 已在 core 归一为 content)。
     *
     * @return mixed
     */
    public function lastBody()
    {
        return $this->wire->lastBody();
    }
}
