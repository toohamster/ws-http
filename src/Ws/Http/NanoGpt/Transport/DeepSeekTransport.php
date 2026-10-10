<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * DeepSeek(design/28 §5 维度 B):OpenAI 兼容,常量即档案(漂移只改此文件)。
 */
final class DeepSeekTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://api.deepseek.com/v1';

    protected const DEFAULT_MODEL = 'deepseek-chat';
}
