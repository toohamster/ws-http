<?php

declare(strict_types=1);

namespace Ws\Http;

/**
 * 请求体构造器(design/11 §5)。
 *
 * json/form/multipart 返回 PreparedBody(含建议 Content-Type,由 Request 统一应用,
 * 用户显式头优先——修复旧版 README 声称自动设置而实现未做的 B6)。
 * file 直接返回 CURLFile(PHP 7.4 恒存在,旧式 @file 语法已废弃)。
 */
final class Body
{
    private function __construct()
    {
    }

    /**
     * JSON 请求体:application/json。编码失败抛 Exception(code:102)。
     *
     * @param mixed $data
     */
    public static function json($data, int $options = 0, int $depth = 512): PreparedBody
    {
        $encoded = json_encode($data, $options, $depth);

        if ($encoded === false) {
            throw new Exception(
                sprintf('JSON encode failed: %s', json_last_error_msg()),
                102
            );
        }

        return new PreparedBody($encoded, 'application/json');
    }

    /**
     * 表单请求体:application/x-www-form-urlencoded;多维数组拍平为 a[b]=c。
     * 标量字符串原样透传(不建议 Content-Type)。
     *
     * @param array|object|string $data
     */
    public static function form($data): PreparedBody
    {
        if (\is_string($data)) {
            return new PreparedBody($data, null);
        }

        return new PreparedBody(
            http_build_query(UrlKit::buildHttpQuery($data)),
            'application/x-www-form-urlencoded'
        );
    }

    /**
     * Multipart 请求体:multipart/form-data。
     * $files 中每个路径转为 CURLFile 并合并进数据。
     *
     * @param array $data  字段数据
     * @param array<string, string> $files [field => filepath]
     */
    public static function multipart(array $data, array $files = []): PreparedBody
    {
        foreach ($files as $name => $path) {
            $data[$name] = self::file($path);
        }

        return new PreparedBody($data, 'multipart/form-data');
    }

    /**
     * 准备上传文件,返回 CURLFile。
     */
    public static function file(string $filename, string $mimeType = '', string $postName = ''): \CURLFile
    {
        return new \CURLFile($filename, $mimeType, $postName);
    }

    /**
     * 原始请求体(引擎 raw-* 模式使用):显式指定 Content-Type,null 则不设置。
     */
    public static function raw(string $data, ?string $contentType = null): PreparedBody
    {
        return new PreparedBody($data, $contentType);
    }
}
