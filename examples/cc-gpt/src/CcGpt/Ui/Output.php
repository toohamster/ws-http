<?php

declare(strict_types=1);

namespace CcGpt\Ui;

/**
 * 终端输出(design/21 §8):ANSI 颜色 + 三列表格;零三方依赖。
 *
 * isatty 检测(非 tty 自动降级纯文本,管道/CI 安全);接口化保留换渲染后端演进空间。
 */
final class Output
{
    private const RESET = "\033[0m";

    /** @var array<string, string> 名称 → ANSI 前景码 */
    private const COLORS = [
        'black'        => "\033[30m",
        'red'          => "\033[31m",
        'green'        => "\033[32m",
        'yellow'       => "\033[33m",
        'blue'         => "\033[34m",
        'magenta'      => "\033[35m",
        'cyan'         => "\033[36m",
        'white'        => "\033[37m",
        'gray'         => "\033[90m",
        'bright_red'   => "\033[91m",
        'bright_green' => "\033[92m",
        'bright_blue'  => "\033[94m",
        'bright_cyan'  => "\033[96m",
    ];

    /** @var bool 颜色开关 */
    private $colors;

    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream 注入(测试);null = STDOUT
     */
    public function __construct($stream = null, ?bool $colors = null)
    {
        $this->stream = $stream ?? \STDOUT;
        $this->colors = $colors ?? (
            \function_exists('posix_isatty')
                ? @posix_isatty($this->stream)
                : false // 无 posix 扩展 → 保守降级
        );
    }

    public function colorsEnabled(): bool
    {
        return $this->colors;
    }

    /**
     * @param string|null $color self::COLORS 键名;null = 不着色
     */
    public function write(string $text, ?string $color = null): void
    {
        fwrite($this->stream, $this->paint($text, $color));
    }

    public function writeln(string $text = '', ?string $color = null): void
    {
        $this->write($text . "\n", $color);
    }

    /**
     * 三列小表(mb_strwidth 对齐,/model 场景)。
     *
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     * @param array<int, string> $colors 每列颜色
     */
    public function table(array $headers, array $rows, array $colors = []): void
    {
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = mb_strwidth($header);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $w = mb_strwidth((string) $cell);
                if ($w > $widths[$i]) {
                    $widths[$i] = $w;
                }
            }
        }

        $render = function (array $cells) use ($widths, $colors): string {
            $line = '';
            foreach ($cells as $i => $cell) {
                $pad = $widths[$i] - mb_strwidth((string) $cell);
                $line .= $this->paint((string) $cell, $colors[$i] ?? null) . str_repeat(' ', $pad + 3);
            }

            return rtrim($line);
        };

        $this->writeln($render($headers));
        foreach ($widths as $w) {
            $this->write(str_repeat('─', $w + 2) . '  ');
        }
        $this->writeln();
        foreach ($rows as $row) {
            $this->writeln($render($row));
        }
    }

    private function paint(string $text, ?string $color): string
    {
        if ($color === null || !$this->colors || !isset(self::COLORS[$color])) {
            return $text;
        }

        return self::COLORS[$color] . $text . self::RESET;
    }
}
