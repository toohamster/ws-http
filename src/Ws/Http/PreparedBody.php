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

    /**
     * 字符串形态语义:字符串直接返回;数组(multipart)转 JSON 表示
     * (日志/诊断等"取内容看一眼"场景;非 multipart 的线上形态,仅表示用)。
     */
    public function __toString(): string
    {
        return \is_array($this->content)
            ? (string) json_encode($this->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $this->content;
    }
}
