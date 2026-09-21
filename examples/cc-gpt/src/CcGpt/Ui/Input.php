<?php

declare(strict_types=1);

namespace CcGpt\Ui;

/**
 * 终端输入:readline 存在则用(历史/编辑),否则 fgets(STDIN)。
 */
final class Input
{
    /** @var resource|null 注入(测试);null = STDIN */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream;
    }

    public function readLine(string $prompt): ?string
    {
        if ($this->stream !== null) {
            fwrite($this->stream === \STDOUT ? \STDERR : $this->stream, $prompt);
            $line = fgets(\STDIN);
        } elseif (\function_exists('readline')) {
            $line = readline($prompt);
        } else {
            fwrite(\STDERR, $prompt);
            $line = fgets(\STDIN);
        }

        if ($line === false) { // EOF(Ctrl-D)
            return null;
        }

        return rtrim($line, "\r\n");
    }
}
