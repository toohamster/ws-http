<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Contract\CookieStoreInterface;
use Ws\Http\Response;

/**
 * 内存 cookie jar(design/16 §4,settings.cookieStore=memory 默认实现)。
 *
 * 按 cookie 名存储,作用域 = 主机;发送时按 URL 主机匹配回放;expires 过期剔除;
 * 不做域名后缀匹配(单场景测试用途,防过度设计)。
 */
final class MemoryCookieStore implements CookieStoreInterface
{
    /** @var array<string, array<string, array{value: string, expires: int}>> host => name => {value, expires} */
    private $jar = [];

    public function cookieHeaderFor(string $url): ?string
    {
        $host = $this->hostOf($url);
        if ($host === null || !isset($this->jar[$host])) {
            return null;
        }

        $now = time();
        $pairs = [];
        foreach ($this->jar[$host] as $name => $entry) {
            if ($entry['expires'] !== 0 && $entry['expires'] < $now) {
                unset($this->jar[$host][$name]); // 过期剔除
                continue;
            }
            $pairs[] = $name . '=' . $entry['value'];
        }

        return $pairs === [] ? null : implode('; ', $pairs);
    }

    public function collectFrom(Response $response, string $url): void
    {
        $host = $this->hostOf($url);
        if ($host === null) {
            return;
        }

        foreach ($response->headers->all('Set-Cookie') as $setCookie) {
            $parsed = $this->parseSetCookie($setCookie);
            if ($parsed === null) {
                continue;
            }
            [$name, $value, $expires] = $parsed;
            $this->jar[$host][$name] = ['value' => $value, 'expires' => $expires];
        }
    }

    /**
     * 解析单条 Set-Cookie:name=value; Expires=...; Path=...
     *
     * @return array{0: string, 1: string, 2: int}|null [name, value, expires(unix ts;0=会话)]
     */
    private function parseSetCookie(string $header): ?array
    {
        $parts = explode(';', $header);
        if ($parts === []) {
            return null;
        }

        $first = array_shift($parts);
        $eq = strpos($first, '=');
        if ($eq === false || $eq === 0) {
            return null;
        }

        $name = trim(substr($first, 0, $eq));
        $value = trim(substr($first, $eq + 1));
        $expires = 0;

        foreach ($parts as $attribute) {
            $attrParts = explode('=', trim($attribute), 2);
            if (strcasecmp($attrParts[0], 'Expires') === 0 && isset($attrParts[1])) {
                $ts = strtotime($attrParts[1]);
                $expires = $ts === false ? 0 : $ts;
            }
        }

        return [$name, $value, $expires];
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
