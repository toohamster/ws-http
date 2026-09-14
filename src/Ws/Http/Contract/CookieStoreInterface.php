<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

use Ws\Http\Response;

/**
 * Cookie 存储契约(design/16 §4;core Contract 定义)。
 */
interface CookieStoreInterface
{
    /**
     * 请求前:返回应注入该 URL 的 Cookie 请求头值,无则 null。
     */
    public function cookieHeaderFor(string $url): ?string;

    /**
     * 响应后:从 Set-Cookie 头收集。
     */
    public function collectFrom(Response $response, string $url): void;
}
