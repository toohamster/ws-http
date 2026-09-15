<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Request;
use Ws\Http\RequestOptions;

/**
 * 默认 RequestFactory:真实 HTTP(S9 Runner 的生产装配)。
 */
final class RequestFactory implements \Ws\Http\Contract\RequestFactoryInterface
{
    public function create(RequestOptions $options): Request
    {
        return new Request($options);
    }
}
