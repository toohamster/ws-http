<?php

declare(strict_types=1);

namespace CcGpt;

/**
 * 运行时设置:cwd/cc-gpt/.settings.json(design/21 §8)。
 */
final class Settings
{
    /** @var string 设置文件所在目录(Sandbox root 同处) */
    private $dir;

    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data 预载(测试注入);null = 从磁盘读
     */
    public function __construct(string $dir, ?array $data = null)
    {
        $this->dir = $dir;
        $this->data = $data ?? $this->read($dir . '/.settings.json');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function save(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(sprintf('Cannot create settings dir "%s"', $this->dir));
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($this->dir . '/.settings.json', ($json === false ? '{}' : $json) . "\n") === false) {
            throw new \RuntimeException(sprintf('Cannot write "%s"', $this->dir . '/.settings.json'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return \is_array($data) ? $data : [];
    }
}
