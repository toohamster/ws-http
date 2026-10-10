<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * Moonshot(design/28 §5 维度 B)。
 */
final class MoonshotTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://api.moonshot.cn/v1';

    protected const DEFAULT_MODEL = 'moonshot-v1-8k';
}
