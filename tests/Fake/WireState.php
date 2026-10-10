<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Fake;

/**
 * 链路态对象(FakeChatRequest 的状态载体,tests/Fake 规格)。
 *
 * 为什么独立存在(design/28 D1,debugging.md 案例 8):core 契约下请求经
 * withOptions(clone) 派生执行,Request 实例只是链路上的过客;帧序列/帧
 * 指针/发送捕获属于**链路**而非某个实例。本对象由 FakeChatRequest 以属性
 * 句柄持有——clone 拷贝句柄,克隆内推进帧指针、追加捕获,测试持有的原
 * 对象全部可见。不使用 static(无全局态、无跨测试污染、无序号簿记)。
 *
 * @internal 仅供 FakeChatRequest 使用
 */
final class WireState
{
    /** @var array<int, array{0: int, 1: string, 2?: string}> 状态码帧序列 */
    private array $frames;

    /** @var int 帧指针(下一次执行取第几帧) */
    private int $index = 0;

    /** @var array<int, array{method: string, url: string, body: mixed, headers: array}> 发送捕获 */
    private array $captured = [];

    /**
     * @param array<int, array{0: int, 1: string, 2?: string}> $frames
     */
    public function __construct(array $frames)
    {
        $this->frames = $frames;
    }

    /**
     * 取当前帧并推进;序列耗尽后停在末帧(重试场景:429,429,200)。
     *
     * @return array{0: int, 1: string, 2?: string}
     */
    public function next(): array
    {
        $frame = $this->frames[min($this->index, count($this->frames) - 1)];
        $this->index++;

        return $frame;
    }

    /**
     * @param array{method: string, url: string, body: mixed, headers: array} $record
     */
    public function capture(array $record): void
    {
        $this->captured[] = $record;
    }

    /**
     * @return array<int, array{method: string, url: string, body: mixed, headers: array}>
     */
    public function captured(): array
    {
        return $this->captured;
    }

    /**
     * 最近一次发送的请求体;未发送过返回 null。
     *
     * @return mixed
     */
    public function lastBody()
    {
        return $this->captured === [] ? null : $this->captured[count($this->captured) - 1]['body'];
    }
}
