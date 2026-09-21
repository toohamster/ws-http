<?php

declare(strict_types=1);

namespace CcGpt;

/**
 * 命令注册表(design/21 §8):壳层扩展点。
 */
final class CommandRegistry
{
    /** @var array<string, CommandInterface> */
    private $commands = [];

    /**
     * @throws \InvalidArgumentException 重名
     */
    public function register(CommandInterface $command): void
    {
        $name = $command->name();
        if (isset($this->commands[$name])) {
            throw new \InvalidArgumentException(sprintf('Command "/%s" already registered', $name));
        }
        $this->commands[$name] = $command;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * @throws \InvalidArgumentException 未知名
     */
    public function get(string $name): CommandInterface
    {
        if (!isset($this->commands[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown command "/%s"', $name));
        }

        return $this->commands[$name];
    }

    /**
     * @return CommandInterface[]
     */
    public function all(): array
    {
        return $this->commands;
    }
}
