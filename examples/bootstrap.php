<?php

/**
 * 示例公共引导:加载 composer autoload。
 * 每个示例独立可运行:php74 examples/openai-chat/run.php
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

date_default_timezone_set('PRC');
