<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class ZhipuDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://open.bigmodel.cn/api/paas/v4';

    protected const DEFAULT_MODEL = 'glm-4-flash';

    protected function getDriverName(): string
    {
        return 'zhipu';
    }
}
