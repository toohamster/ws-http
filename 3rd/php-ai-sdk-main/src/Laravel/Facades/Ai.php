<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SnowmanNunu\Ai\DTO\AiResponse chat(array $messages, array $options = [])
 * @method static \Generator stream(array $messages, array $options = [])
 * @method static \SnowmanNunu\Ai\Contracts\AiDriverInterface driver(?string $driver = null)
 * @method static void extend(string $driver, \Closure $callback)
 * @method static void setDefaultDriver(string $driver)
 * @method static ?string getDefaultDriver()
 */
class Ai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ai';
    }
}
