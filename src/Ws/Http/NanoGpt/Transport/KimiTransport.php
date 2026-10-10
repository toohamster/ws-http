<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * Kimi(已换域名的新形态,来源 3rd/php-ai-sdk 实测)(design/28 §5 维度 B)。
 */
final class KimiTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://api.kimi.com/coding/v1';

    protected const DEFAULT_MODEL = 'kimi-coding';
}
