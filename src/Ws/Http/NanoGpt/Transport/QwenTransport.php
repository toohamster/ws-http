<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * 通义千问(百炼兼容模式)(design/28 §5 维度 B)。
 */
final class QwenTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://dashscope.aliyuncs.com/compatible-mode/v1';

    protected const DEFAULT_MODEL = 'qwen-turbo';
}
