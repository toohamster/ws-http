<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class XiaomiMimoDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://token-plan-cn.xiaomimimo.com/v1';

    protected const DEFAULT_MODEL = 'mimo-v2.5-pro';

    protected function getDriverName(): string
    {
        return 'xiaomi-mimo';
    }
}
