<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class DeepSeekDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://api.deepseek.com/v1';

    protected const DEFAULT_MODEL = 'deepseek-chat';

    protected function getDriverName(): string
    {
        return 'deepseek';
    }
}
