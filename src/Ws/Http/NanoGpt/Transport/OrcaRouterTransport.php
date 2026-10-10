<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * OrcaRouter(项目验证源;N4 冒烟 3/3)(design/28 §5 维度 B)。
 * free 过滤等特殊逻辑将来覆写差异槽落此,不进基类。
 */
final class OrcaRouterTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://api.orcarouter.ai/v1';

    protected const DEFAULT_MODEL = 'deepseek/deepseek-v4-flash-free';
}
