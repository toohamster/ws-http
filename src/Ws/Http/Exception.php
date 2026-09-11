<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * ws-http 异常体系基类。
 *
 * 错误码段规划(design/10 §5):
 *   100–199 core 传输 / 200–299 表达式 / 300–399 断言 / 400–499 引擎 / 500–599 plugin
 */
class Exception extends \RuntimeException
{
}
