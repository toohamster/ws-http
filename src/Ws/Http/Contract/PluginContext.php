<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

use Ws\Http\Assert\Comparison;

/**
 * Plugin 装配面(design/17 §2):注册表暴露给 plugin 的最小能力集合。
 *
 * 机制最小化承诺:只有这四个注册方法,无生命周期钩子/版本协商。
 */
final class PluginContext
{
    /** @var array<string, AuthProviderInterface> 认证器:名字 → 实现 */
    private $authProviders = [];

    /** @var array<string, object> plugin 自有服务(语义化客户端等) */
    private $services = [];

    /**
     * 注册认证方式(名字建议 plugin 前缀,如 "openai.bearer")。
     */
    public function addAuth(string $name, AuthProviderInterface $provider): void
    {
        $this->authProviders[$name] = $provider;
    }

    /**
     * 注册比较操作符(经 Assertion 层 Extension 机制)。
     */
    public function addComparator(string $op, ComparatorInterface $impl): void
    {
        Comparison::register($op, $impl);
    }

    /**
     * 注册提取 source(经变量系统 Extension 机制,design/15 §3)。
     */
    public function addExtractor(string $source, ExtractorInterface $impl): void
    {
        \Ws\Http\Automated\VarExtractor::registerSource($source, $impl);
    }

    /**
     * 挂载 plugin 自有服务(端点定义、语义化客户端实例)。
     */
    public function share(string $key, object $service): void
    {
        $this->services[$key] = $service;
    }

    /**
     * @return array<string, AuthProviderInterface>
     */
    public function authProviders(): array
    {
        return $this->authProviders;
    }

    /**
     * @return array<string, object>
     */
    public function services(): array
    {
        return $this->services;
    }
}
