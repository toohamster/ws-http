<?php

declare(strict_types=1);

namespace Ws\Http\Plugin;

use Ws\Http\Contract\PluginContext;
use Ws\Http\Contract\PluginInterface;
use Ws\Http\Exception;

/**
 * Plugin 注册表(design/17 §3):plugin 机制的唯一公开入口。
 *
 * - 内建 plugin 由 bootDefaults() 装配(openai/wordpress);
 * - 第三方经 register() 注入(重名抛 501);
 * - 静态简易实现(本库无容器哲学);多套隔离配置时可实例化使用。
 */
final class PluginRegistry
{
    /** @var array<string, PluginInterface> */
    private static $plugins = [];

    /** @var array<string, AuthProviderInterface> 全局认证器(来自全部 plugin) */
    private static $authProviders = [];

    /** @var array<string, object> 全局共享服务 */
    private static $services = [];

    /** @var bool */
    private static $booted = false;

    /**
     * 注册内建 plugin(幂等)。
     */
    public static function bootDefaults(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::register(new OpenAI\OpenAIPlugin());
        self::register(new WordPress\WordPressPlugin());
    }

    /**
     * 注册自定义 plugin。
     */
    public static function register(PluginInterface $plugin): void
    {
        $name = $plugin->name();

        if (isset(self::$plugins[$name])) {
            throw new Exception(sprintf('Plugin "%s" is already registered', $name), 501);
        }

        self::$plugins[$name] = $plugin;

        $ctx = new PluginContext();
        $plugin->register($ctx);

        // 合并装配产物到全局面
        foreach ($ctx->authProviders() as $authName => $provider) {
            self::$authProviders[$authName] = $provider;
        }
        foreach ($ctx->services() as $key => $service) {
            self::$services[$key] = $service;
        }
    }

    public static function has(string $name): bool
    {
        return isset(self::$plugins[$name]);
    }

    /**
     * 取 plugin 挂载的语义化客户端/服务。
     */
    public static function service(string $key): object
    {
        if (!isset(self::$services[$key])) {
            throw new Exception(sprintf('Unknown plugin service: %s (registered plugins: %s)', $key, implode(', ', array_keys(self::$plugins))), 501);
        }

        return self::$services[$key];
    }

    /**
     * 取认证器(场景脚本 auth 字段与 plugin 门面共用)。
     */
    public static function auth(string $name): \Ws\Http\Contract\AuthProviderInterface
    {
        self::bootDefaults();

        if (!isset(self::$authProviders[$name])) {
            throw new Exception(sprintf('Unknown auth provider: %s', $name), 502);
        }

        return self::$authProviders[$name];
    }

    /**
     * 已注册的 plugin 名列表。
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::$plugins);
    }

    /**
     * 仅测试用:清空注册表。
     */
    public static function resetForTest(): void
    {
        self::$plugins = [];
        self::$authProviders = [];
        self::$services = [];
        self::$booted = false;
    }
}
