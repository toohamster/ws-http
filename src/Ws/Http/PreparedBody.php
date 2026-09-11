<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * 请求体构造结果值对象(design/11 §5):内容 + 建议 Content-Type。
 *
 * @immutable 构造后不可变(PHP 7.4 无 readonly,按约定只读)
 */
final class PreparedBody
{
    /** @var string|array 请求体内容(string 或 multipart 数组) */
    public $content;

    /** @var string|null 建议 Content-Type;null = 不设置 */
    public $contentType;

    /**
     * @param string|array $content
     */
    public function __construct($content, ?string $contentType = null)
    {
        $this->content = $content;
        $this->contentType = $contentType;
    }
}
