<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;
use CcGpt\ModelProvider\AbstractServiceAdapter;

/**
 * /init:服务适配器选择 + key/url 配置(design/21 §8.2)。
 *
 * 交互流:列适配器菜单 → 选择 → 按适配器自报 prompts 收集 → 写 .settings.json
 * { adapter, apiKey, baseUrl }。参数化退化(非交互/测试):/init --adapter <id> <key> <url>。
 * 重跑:只改 adapter 时保留已有 key/url;存量无 adapter 字段视为未选择。
 */
final class Init implements CommandInterface
{
    /** @var array<string, class-string<AbstractServiceAdapter>> 适配器描述表(新适配器 = 一个类 + 此表加一行) */
    private const ADAPTERS = [
        \CcGpt\ModelProvider\OrcaRouterAdapter::class,
        \CcGpt\ModelProvider\GenericAdapter::class,
    ];

    public function name(): string
    {
        return 'init';
    }

    public function description(): string
    {
        return 'Configure model provider adapter, API key and base URL (writes .settings.json)';
    }

    /**
     * 适配器描述表(测试/组装可见)。
     *
     * @return array<int, string>
     */
    public static function adapterClasses(): array
    {
        return self::ADAPTERS;
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;
        $settings = $context->settings;

        // 参数化形态:/init --adapter <id> <key> <url>
        if (isset($args[0]) && $args[0] === '--adapter') {
            $adapterId = (string) ($args[1] ?? '');
            $class = $this->adapterClassById($adapterId);
            if ($class === null) {
                $out->writeln(sprintf('unknown adapter "%s" — available: %s', $adapterId, implode(', ', $this->adapterIds())), 'red');

                return true;
            }
            $values = [
                'apiKey'  => trim((string) ($args[2] ?? '')),
                'baseUrl' => trim((string) ($args[3] ?? '')),
            ];
            if ($values['apiKey'] === '') {
                $out->writeln('aborted: empty key', 'yellow');

                return true;
            }

            return $this->save($context, $class, $values);
        }

        // 交互流:菜单选择
        $classes = self::ADAPTERS;
        $out->writeln('select model provider adapter:');
        foreach ($classes as $i => $class) {
            $out->writeln(sprintf('  %d) %s', $i + 1, $class::label()));
        }

        $choice = $context->input->readLine(sprintf('adapter [1-%d]: ', count($classes)));
        $index = (int) trim((string) $choice) - 1;
        if ($choice === null || !isset($classes[$index])) {
            $out->writeln('aborted: invalid adapter selection', 'yellow');

            return true;
        }
        $class = $classes[$index];

        // 按适配器自报 prompts 收集(重跑保留已有值作为默认)
        $values = [];
        foreach ($class::prompts() as $spec) {
            $existing = $settings->get($spec['key']);
            $default = $existing !== null && $existing !== ''
                ? (string) $existing
                : (string) ($spec['default'] ?? '');
            $hint = $default !== '' ? sprintf(' [%s]', $default) : '';
            $line = $context->input->readLine($spec['prompt'] . $hint . ': ');
            $value = $line === null ? '' : trim($line);
            if ($value === '') {
                $value = $default;
            }
            $values[$spec['key']] = $value;
        }
        if (($values['apiKey'] ?? '') === '') {
            $out->writeln('aborted: empty key', 'yellow');

            return true;
        }

        return $this->save($context, $class, $values);
    }

    /**
     * @param class-string<AbstractServiceAdapter> $class
     * @param array<string, string> $values
     */
    private function save(Context $context, string $class, array $values): bool
    {
        $context->settings->set('adapter', $class::id());
        foreach ($values as $key => $value) {
            if ($value !== '') {
                $context->settings->set($key, $value);
            }
        }
        $context->settings->save();
        $context->output->writeln(
            sprintf('adapter set to %s; settings saved to %s/.settings.json', $class::id(), $context->settings->dir()),
            'green'
        );

        return true;
    }

    private function adapterClassById(string $id): ?string
    {
        foreach (self::ADAPTERS as $class) {
            if ($class::id() === $id) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function adapterIds(): array
    {
        $ids = [];
        foreach (self::ADAPTERS as $class) {
            $ids[] = $class::id();
        }

        return $ids;
    }
}
