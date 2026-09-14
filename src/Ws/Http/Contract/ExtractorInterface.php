<?php

declare(strict_types=1);

namespace Ws\Http\Contract;

use Ws\Http\Response;

/**
 * 变量提取 source 契约(design/15 §3)。
 *
 * functional 内建 json/header/raw_body/status/time/template 六种默认 source;
 * plugin/业务方经注册扩展(如 XML 提取)。
 */
interface ExtractorInterface
{
    /**
     * 从响应提取值。
     *
     * @param string $path source 语义的定位器:json=JSONPath / header=头名 /
     *                     template=模式串;其余忽略
     * @return ExtractionOutcome 提取结果(空集不是异常,由调用方按 onMissing 处理)
     */
    public function extract(Response $response, string $path): ExtractionOutcome;
}
