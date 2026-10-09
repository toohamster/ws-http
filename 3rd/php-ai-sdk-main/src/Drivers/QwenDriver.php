<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Drivers;

class QwenDriver extends OpenAiDriver
{
    protected const DEFAULT_BASE_URL = 'https://dashscope.aliyuncs.com/compatible-mode/v1';

    protected const DEFAULT_MODEL = 'qwen-turbo';

    protected function getDriverName(): string
    {
        return 'qwen';
    }
}
