<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 模型目录条目(design/21 §8.2 四次评审:ModelInfo 属性模型,Java Bean 心智)。
 *
 * 契约定义通用属性集;适配器(实例服务类)把各自响应差异 set 进本模型;
 * 消费方(/model、能力对账 design/24 §6.1)只读属性,不感知服务差异。
 *
 * 能力参数拿不到 = null(条目照常有效);provenance 标记能力值来源。
 */
final class ModelInfo
{
    /** 能力值来源:模型列表接口声明(服务商) */
    public const SRC_API = 'api';

    /** 能力值来源:模型自报(提示词探测;可能不准,展示需标注) */
    public const SRC_SELF = 'self-report';

    /** 能力值来源:用户录入/手设 */
    public const SRC_MANUAL = 'manual';

    /** @var string 模型 id(如 deepseek/deepseek-v4-flash-free) */
    private $id;

    /** @var string 展示名 */
    private $name;

    /** @var string 定价描述(展示用;无 = 空串) */
    private $pricing;

    /** @var int|null 上下文窗口(tokens);拿不到 = null */
    private $contextWindow;

    /** @var int|null 最大输出 tokens;拿不到 = null */
    private $maxOutputTokens;

    /** @var string 能力值来源(SRC_API / SRC_SELF / SRC_MANUAL) */
    private $provenance;

    public function __construct(
        string $id,
        string $name,
        string $pricing = '',
        ?int $contextWindow = null,
        ?int $maxOutputTokens = null,
        string $provenance = self::SRC_API
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->pricing = $pricing;
        $this->contextWindow = $contextWindow;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->provenance = $provenance;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function pricing(): string
    {
        return $this->pricing;
    }

    public function contextWindow(): ?int
    {
        return $this->contextWindow;
    }

    public function maxOutputTokens(): ?int
    {
        return $this->maxOutputTokens;
    }

    public function provenance(): string
    {
        return $this->provenance;
    }
}
