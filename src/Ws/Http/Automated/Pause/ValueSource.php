<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Pause;

/**
 * 取值策略契约(design/22 §3):pause 步骤"从哪拿值"的注册点。
 *
 * 与 VarExtractor 的 extract source(design/15)是两个独立注册点:
 * 一个面向"场景中途取值"(本接口),一个面向"从当前响应提取"。
 */
interface ValueSource
{
    /**
     * 策略 id:'stdin' / 'environment' / 自定义名;'poll:<name>' 由 PauseRegistry 解析包装。
     */
    public static function id(): string;

    /**
     * 取值。
     *
     * @param array<string, mixed> $options 步骤 options 透传
     */
    public function fetch(array $options): ValueResult;
}
