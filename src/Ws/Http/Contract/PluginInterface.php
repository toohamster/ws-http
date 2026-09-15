<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

/**
 * Plugin 注册契约(design/17 §2):三方系统适配的统一入口。
 *
 * 产出物三件套 = 认证封装 + 端点定义 + 语义化方法;
 * plugin 之间互不依赖、core/functional 不感知任何具体 plugin(依赖单向)。
 */
interface PluginInterface
{
    /**
     * 唯一标识:'openai' | 'wordpress' | …(重名注册抛 Exception 501)。
     */
    public function name(): string;

    /**
     * 装配点:把 plugin 提供的认证器/提取器/比较器/自有服务挂到运行环境。
     */
    public function register(PluginContext $ctx): void;
}
