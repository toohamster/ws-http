<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 已知厂商档案表(design/28 §2,V1):静态知识层——厂商漂移(baseUrl 换域名等)只改此表。
 *
 * **档案非工厂**(§8.2 约束延续):不做注册表/自动发现;接入路径不变——OpenAI 兼容系
 * 换 baseUrl 即用,协议级不同(Claude 类)= 新 Plugin(design/17 坑位)。表只回答
 * "这家服务的默认参数是什么"。
 *
 * 数据快照(2026-10-09,来源:3rd/php-ai-sdk-main 对照,该库已实测各家 baseUrl;
 * Kimi 为其已换域名的新形态)。不承诺时效,使用者可覆盖。
 */
final class VendorCatalog
{
    /** @var array<string, array{name: string, baseUrl: string, defaultModel: string, protocol: string}> */
    private static $vendors = [
        'openai' => [
            'name'         => 'OpenAI',
            'baseUrl'      => 'https://api.openai.com/v1',
            'defaultModel' => 'gpt-4o-mini',
            'protocol'     => 'openai-compatible',
        ],
        'deepseek' => [
            'name'         => 'DeepSeek',
            'baseUrl'      => 'https://api.deepseek.com/v1',
            'defaultModel' => 'deepseek-chat',
            'protocol'     => 'openai-compatible',
        ],
        'qwen' => [
            'name'         => '通义千问',
            'baseUrl'      => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'defaultModel' => 'qwen-turbo',
            'protocol'     => 'openai-compatible',
        ],
        'kimi' => [
            'name'         => 'Kimi',
            'baseUrl'      => 'https://api.kimi.com/coding/v1',
            'defaultModel' => 'kimi-coding',
            'protocol'     => 'openai-compatible',
        ],
        'moonshot' => [
            'name'         => 'Moonshot',
            'baseUrl'      => 'https://api.moonshot.cn/v1',
            'defaultModel' => 'moonshot-v1-8k',
            'protocol'     => 'openai-compatible',
        ],
        'minimax' => [
            'name'         => 'MiniMax',
            'baseUrl'      => 'https://api.minimax.chat/v1',
            'defaultModel' => 'abab6.5s-chat',
            'protocol'     => 'openai-compatible',
        ],
        'claude' => [
            'name'         => 'Claude',
            'baseUrl'      => 'https://api.anthropic.com/v1',
            'defaultModel' => 'claude-sonnet-4-6',
            'protocol'     => 'anthropic',
        ],
        'orcarouter' => [
            'name'         => 'OrcaRouter',
            'baseUrl'      => 'https://api.orcarouter.ai/v1',
            'defaultModel' => '',
            'protocol'     => 'openai-compatible',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * 全部已知厂商档案。
     *
     * @return array<string, array{name: string, baseUrl: string, defaultModel: string, protocol: string}>
     */
    public static function known(): array
    {
        return self::$vendors;
    }

    /**
     * 单家查询;未知 id 返回 null(不做兜底猜测)。
     *
     * @return array{name: string, baseUrl: string, defaultModel: string, protocol: string}|null
     */
    public static function get(string $id): ?array
    {
        return self::$vendors[$id] ?? null;
    }
}
