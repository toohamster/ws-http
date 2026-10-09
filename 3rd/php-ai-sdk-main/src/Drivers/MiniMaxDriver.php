<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class MiniMaxDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://api.minimax.chat/v1';

    protected const DEFAULT_MODEL = 'abab6.5s-chat';

    protected function getDriverName(): string
    {
        return 'minimax';
    }
}
