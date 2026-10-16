<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

use Ws\Http\NanoGpt\ModelInfo;

/**
 * OrcaRouter(项目验证源;N4 冒烟 3/3)(design/28 §5 维度 B)。
 * 特殊逻辑落本类:free 过滤(S3 观察:窗口信息只在 description 自由文本,
 * 不从文本猜——能力参数走选择后探测/手动级联)。
 */
final class OrcaRouterTransport extends OpenAiCompatibleTransport
{
    protected const BASE_URL = 'https://api.orcarouter.ai/v1';

    protected const DEFAULT_MODEL = 'deepseek/deepseek-v4-flash-free';

    /**
     * free 过滤(实例行为选择):id 不含 free → 过滤;pricing 是对象
     * {request: "..."}(S3 观察事实)→ 取其 request 值。
     *
     * @param object $entry
     */
    protected function modelFromEntry(object $entry): ?ModelInfo
    {
        $id = (string) ($entry->id ?? '');
        if ($id === '' || stripos($id, 'free') === false) {
            return null;
        }

        $pricing = '';
        if (isset($entry->pricing) && \is_object($entry->pricing)) {
            $pricing = (string) ($entry->pricing->request ?? '');
        }

        return new ModelInfo($id, (string) ($entry->name ?? $id), $pricing, null, null, ModelInfo::SRC_API);
    }
}
