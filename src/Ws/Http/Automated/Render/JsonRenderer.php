<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Render;

use Ws\Http\Automated\Dataset\BatchReport;
use Ws\Http\Automated\Report;

/**
 * JSON 渲染(design/16 §5.1):toArray(JSON 报告契约)+ 稳定编码参数。
 */
final class JsonRenderer implements ReportRendererInterface
{
    /**
     * @param Report|BatchReport $report
     */
    public function render($report): string
    {
        $json = json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return ($json === false ? '{}' : $json) . "\n";
    }
}
