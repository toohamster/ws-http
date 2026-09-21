<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /init:写入 api-key 到 cc-gpt/.settings.json(配置三层:env > config.php > /init)。
 */
final class Init implements CommandInterface
{
    public function name(): string
    {
        return 'init';
    }

    public function description(): string
    {
        return 'Configure API key and base URL (writes cc-gpt/.settings.json)';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        $key = $args[0] ?? $context->input->readLine('API key: ');
        if ($key === null || trim($key) === '') {
            $out->writeln('aborted: empty key', 'yellow');

            return true;
        }

        $baseUrl = $args[1] ?? $context->input->readLine('Base URL [https://api.openai.com/v1]: ');
        $context->settings->set('apiKey', trim((string) $key));
        if ($baseUrl !== null && trim($baseUrl) !== '') {
            $context->settings->set('baseUrl', trim($baseUrl));
        }
        $context->settings->save();

        $out->writeln('settings saved to ' . $context->settings->dir() . '/.settings.json', 'green');

        return true;
    }
}
