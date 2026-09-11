<?php

declare(strict_types=1);

namespace Ws\Http\Assert;

use Ws\Http\Exception;

/**
 * 断言失败异常(仅 Watcher 流式形态抛出;引擎内部收集 AssertionResult,不抛)。
 *
 * code 301 状态码 / 302 头缺失 / 303 头值 / 304 体 / 305 JSON·时间·其他(design/10 §5)。
 */
class AssertionException extends Exception
{
}
