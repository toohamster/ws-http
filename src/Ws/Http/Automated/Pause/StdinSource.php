<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Pause;

/**
 * stdin 取值策略(design/22 §3.2):一次性阻塞读取标准输入。
 *
 * 零时间参数(人在场场景,卡住由操作者 Ctrl-C);EOF(无输入流/CI 环境)
 * 返回 missing。
 */
final class StdinSource implements ValueSource
{
    /** @var resource|null 输入流句柄(可注入,测试用) */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream;
    }

    public static function id(): string
    {
        return 'stdin';
    }

    /**
     * options: { message?: string(提示语,输出到 stderr), mask?: bool(默认 false,回显为 *) }
     *
     * @param array<string, mixed> $options
     */
    public function fetch(array $options): ValueResult
    {
        $message = (string) ($options['message'] ?? '');
        if ($message !== '') {
            \fwrite(\STDERR, $message . ': ');
        }

        $stream = $this->stream ?? \STDIN;
        $raw = \is_resource($stream) ? \fgets($stream) : false;

        if ($raw === false) {
            if ($message !== '') {
                \fwrite(\STDERR, "\n");
            }

            return ValueResult::missing('stdin closed (EOF) without input');
        }

        $value = \trim((string) $raw);
        $mask = (bool) ($options['mask'] ?? false);
        \fwrite(\STDERR, ($mask ? \str_repeat('*', \strlen($value)) : $value) . "\n");

        return ValueResult::got($value);
    }
}
