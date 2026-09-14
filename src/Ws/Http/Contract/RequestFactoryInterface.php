<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

use Ws\Http\Request;
use Ws\Http\RequestOptions;

/**
 * HTTP 工厂契约(S9):引擎与 L1 解耦的关键,单测注入 mock。
 */
interface RequestFactoryInterface
{
    public function create(RequestOptions $options): Request;
}
