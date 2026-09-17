<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Pause;

/**
 * environment 取值策略(design/22 §3.2):读环境变量或文件首行,读一次即返回。
 *
 * 零时间参数:需要"等一等再取"由脚本显式用 delay 表达,引擎不隐式轮询。
 */
final class EnvironmentSource implements ValueSource
{
    public static function id(): string
    {
        return 'environment';
    }

    /**
     * options: { env: string(环境变量名) } 或 { file: string(文件路径,取首行 trim) }
     * 两者可同给(env 优先);都未配置 → missing(原因即配置缺失)。
     *
     * @param array<string, mixed> $options
     */
    public function fetch(array $options): ValueResult
    {
        $envName = isset($options['env']) ? (string) $options['env'] : '';
        $file = isset($options['file']) ? (string) $options['file'] : '';

        if ($envName === '' && $file === '') {
            return ValueResult::missing('environment source requires "env" or "file" option');
        }

        if ($envName !== '') {
            $value = \getenv($envName);
            if ($value !== false && $value !== '') {
                return ValueResult::got($value);
            }

            if ($file === '') {
                return ValueResult::missing(sprintf('environment variable "%s" is not set', $envName));
            }
        }

        $raw = @\file_get_contents($file);
        if ($raw === false) {
            return ValueResult::missing(sprintf('cannot read file "%s"', $file));
        }

        $line = \trim((string) \strtok($raw, "\n"));
        if ($line === '') {
            return ValueResult::missing(sprintf('file "%s" is empty', $file));
        }

        return ValueResult::got($line);
    }
}
