<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class MoonshotDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://api.moonshot.cn/v1';

    protected const DEFAULT_MODEL = 'moonshot-v1-8k';

    protected function getDriverName(): string
    {
        return 'moonshot';
    }
}
