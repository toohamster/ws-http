<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * MiniMax(design/28 §5 维度 B)。
 */
final class MiniMaxTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://api.minimax.chat/v1';

    protected const DEFAULT_MODEL = 'abab6.5s-chat';
}
