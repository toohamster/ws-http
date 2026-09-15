<?php

declare(strict_types=1);

namespace Ws\Http\Plugin\WordPress;

use Ws\Http\Body;
use Ws\Http\Request;
use Ws\Http\RequestOptions;
use Ws\Http\Response;

/**
 * /wp-json/wp/v2/media
 */
final class MediaEndpoint
{
    private $http;
    private $authConfig;
    private $restBase;

    /**
     * @param array<string, string> $authConfig [user, appPassword]
     */
    public function __construct(Request $http, array $authConfig, string $restBase)
    {
        $this->http = $http;
        $this->authConfig = $authConfig;
        $this->restBase = $restBase;
    }

    /**
     * 上传文件(CURLFile multipart,复用 core)。
     *
     * @param array<string, mixed> $extra 附加字段(title 等)
     */
    public function upload(string $filepath, array $extra = []): Response
    {
        $payload = array_merge($extra, ['file' => Body::file($filepath)]);

        return $this->authorized()->post($this->restBase . '/media', [], $payload);
    }

    private function authorized(): Request
    {
        $auth = $this->authConfig;

        return $this->http->withOptions(static function (RequestOptions $o) use ($auth): RequestOptions {
            return $o->withAuth($auth['user'], $auth['appPassword']);
        });
    }
}
