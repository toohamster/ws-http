<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Contract\RequestFactoryInterface;
use Ws\Http\Request;
use Ws\Http\RequestOptions;

/**
 * 静态 Request 工厂:恒返回同一注入实例(design/27 O2)——场景取数在
 * ApiFetchTool 内复用调用方注入的 http(Request 白名单已在 execute 前置校验)。
 *
 * 与 RequestFactory(每次 new 真实 Request)相对:注入式装配面,测试/工具集成用。
 */
final class StaticRequestFactory implements RequestFactoryInterface
{
    /** @var Request */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function create(RequestOptions $options): Request
    {
        return $this->request->withOptions(static fn (RequestOptions $o): RequestOptions => $options);
    }
}
