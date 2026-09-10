<?php namespace Ws\Http\Request;

use Ws\Http\Request as Request;
use Ws\Http\Exception as Exception;

class Body
{
    /**
     * Prepares a file for upload. To be used inside the parameters declaration for a request.
     * @param string $filename The file path
     * @param string $mimetype MIME type
     * @param string $postname the file name
     * @return string|\CURLFile
     */
    public static function file($filename, $mimetype = '', $postname = '')
    {
        if (class_exists('CURLFile', false)) {
            return new \CURLFile($filename, $mimetype, $postname);
        }

        if (function_exists('curl_file_create')) {
            return curl_file_create($filename, $mimetype, $postname);
        }

        return sprintf('@%s;filename=%s;type=%s', $filename, $postname ?: basename($filename), $mimetype);
    }

    public static function json($data)
    {
        if (!function_exists('json_encode')) {
            throw new Exception('JSON Extension not available');
        }

        return json_encode($data);
    }

    public static function form($data)
    {
        if (is_array($data) || is_object($data) || $data instanceof \Traversable) {
            return http_build_query(Request::buildHTTPCurlQuery($data));
        }

        return $data;
    }

    public static function multipart($data, $files = false)
    {
        if (is_object($data)) {
            return get_object_vars($data);
        }

        if (!is_array($data)) {
            return [$data];
        }

        if ($files !== false) {
            foreach ($files as $name => $file) {
                $data[$name] = call_user_func([__CLASS__, 'File'], $file);
            }
        }

        return $data;
    }
}
