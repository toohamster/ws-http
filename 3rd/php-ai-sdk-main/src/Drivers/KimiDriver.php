<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class KimiDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://api.kimi.com/coding/v1/';

    protected const DEFAULT_MODEL = 'kimi-coding';

    protected function getDriverName(): string
    {
        return 'kimi';
    }
}
