<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * OpenAI 官方(design/28 §5 维度 B):协议族基准形态,常量即档案。
 */
final class OpenAiTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://api.openai.com/v1';

    protected const DEFAULT_MODEL = 'gpt-4o-mini';
}
