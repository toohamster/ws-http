<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

/**
 * 认证提供者契约(design/17 §3):把认证配置转换为请求配置。
 *
 * core 内建 basic/bearer/header(design/14 §4.2);plugin 可注册新方式
 * (如微信签名、OAuth2 流程)——注册即接入场景脚本 auth 字段与 plugin 门面。
 */
interface AuthProviderInterface
{
    /**
     * @param array<string, mixed> $config 认证配置(basic: user/password;bearer: token;header: value)
     */
    public function apply(\Ws\Http\RequestOptions $options, array $config): \Ws\Http\RequestOptions;
}
