<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Render;

/**
 * 报告渲染契约(design/16 §5.1):单方法薄接口,Report 与 BatchReport 共用。
 *
 * 渲染是消费端职责——库只负责把结构化数据完整交出(toArray 为 JSON 报告契约);
 * 自定义报表(HTML/CSV 等)= 实现本接口一个方法,不建渲染器注册表/模板引擎。
 */
interface ReportRendererInterface
{
    /**
     * @param \Ws\Http\Automated\Report|\Ws\Http\Automated\Dataset\BatchReport $report
     */
    public function render($report): string;
}
