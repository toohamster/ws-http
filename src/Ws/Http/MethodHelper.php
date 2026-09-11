<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * Method 接口的辅助函数(接口不能包含方法体,静态 helper 单独提供)。
 */
final class MethodHelper
{
    private function __construct()
    {
    }

    /**
     * 请求体是否允许用于该方法(design/11 §6)。
     * GET/HEAD/CONNECT 不允许体;其余(含自定义方法)默认允许。
     */
    public static function isBodyAllowed(string $method): bool
    {
        return !\in_array(strtoupper($method), Method::NO_BODY_METHODS, true);
    }
}
