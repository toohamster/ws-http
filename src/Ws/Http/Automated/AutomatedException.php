<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Exception;

/**
 * 自动化引擎异常(design/10 §5):脚本解析失败等无法继续的错误。
 *
 * code 401 = JSON 非法 / 402 = 字段校验失败 / 403+ 引擎内部错误。
 */
class AutomatedException extends Exception
{
}
