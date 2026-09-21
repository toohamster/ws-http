<?php

declare(strict_types=1);

namespace CcGpt;

/**
 * 命令契约(design/21 §8):壳层扩展点——使用者实现本接口并注册即成新命令。
 */
interface CommandInterface
{
    /** 命令名(不含前导 /),如 'model' */
    public function name(): string;

    /** 一行说明(/help 展示) */
    public function description(): string;

    /**
     * 执行。返回 false = 请求退出 REPL。
     *
     * @param string[] $args 命令后的空格分段参数
     */
    public function execute(array $args, Context $context): bool;
}
