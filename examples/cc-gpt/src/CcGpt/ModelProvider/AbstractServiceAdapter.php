<?php

declare(strict_types=1);

namespace CcGpt\ModelProvider;

use Ws\Http\NanoGpt\Transport\ChatTransportInterface;

/**
 * 服务适配器抽象层(design/21 §8.2;design/28 D3 薄壳化)。
 *
 * 目录解析归 Transport(能力档案 capabilities),适配器只剩共享骨架
 * (final 锁定:容错 + 委托)与自报契约(id/label/prompts)——
 * "服务身份 → Transport 配置"归壳,"模型目录解析"归 Transport。
 */
abstract class AbstractServiceAdapter implements ModelSourceInterface
{
    /** @var ChatTransportInterface */
    protected $transport;

    public function __construct(ChatTransportInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * 模板方法(final):容错(服务失败 → 空列表)是共享语义。
     *
     * @return array<int, \Ws\Http\NanoGpt\ModelInfo>
     */
    final public function models(): array
    {
        try {
            return $this->transport->capabilities()['models'];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 自报契约(§8.2):选择标识(写进 settings.adapter;/init --adapter 参数)。
     */
    abstract public static function id(): string;

    /**
     * 自报契约(§8.2):/init 菜单展示文案。
     */
    abstract public static function label(): string;

    /**
     * 自报契约(§8.2):/init 收集输入项(各适配器不同;/init 按此提问,不写死)。
     *
     * @return array<int, array{key: string, prompt: string, default?: string}>
     */
    abstract public static function prompts(): array;
}
