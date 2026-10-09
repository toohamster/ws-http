<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class WenxinDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://qianfan.baidubce.com/v2';

    protected const DEFAULT_MODEL = 'ernie-4.0-8k-latest';

    protected function getDriverName(): string
    {
        return 'wenxin';
    }
}
