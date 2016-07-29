<?php namespace Ws\Http;

class ARequest
{

    /**
     * Set JSON decode mode
     *
     * @param bool $assoc When TRUE, returned objects will be converted into associative arrays.
     * @param integer $depth User specified recursion depth.
     * @param integer $options Bitmask of JSON decode options. Currently only JSON_BIGINT_AS_STRING is supported (default is to cast large integers as floats)
     * @return array
     */
    public static function jsonOpts($assoc = false, $depth = 512, $options = 0)
    {
        return Request::create('default')->jsonOpts($assoc, $depth, $options);
    }

    /**
     * Verify SSL peer
     *
     * @param bool $enabled enable SSL verification, by default is true
     * @return bool
     */
    public static function verifyPeer($enabled)
    {
        return Request::create('default')->verifyPeer($enabled);
    }

    /**
     * Verify SSL host
     *
     * @param bool $enabled enable SSL host verification, by default is true
     * @return bool
     */
    public static function verifyHost($enabled)
    {
        return Request::create('default')->verifyPeer($enabled);
    }

    /**
     * Verify SSL File
     *
     * @param string $file SSL verification file
     * @return string
     */
    public static function verifyFile($file)
    {
        return Request::create('default')->verifyFile($file);
    }

    /**
     * Get Verify SSL File
     * 
     * @return string
     */
    public static function getVerifyFile()
    {
        return Request::create('default')->getVerifyFile();
    }

    /**
     * Set a timeout
     *
     * @param integer $seconds timeout value in seconds
     * @return integer
     */
    public static function timeout($seconds)
    {
        return Request::create('default')->timeout($seconds);
    }

    /**
     * Set default headers to send on every request
     *
     * @param array $headers headers array
     * @return array
     */
    public static function defaultHeaders($headers)
    {
        return Request::create('default')->defaultHeaders($headers);
    }

    /**
     * Set a new default header to send on every request
     *
     * @param string $name header name
     * @param string $value header value
     * @return string
     */
    public static function defaultHeader($name, $value)
    {
        return Request::create('default')->defaultHeader($name, $value);
    }

    /**
     * Clear all the default headers
     */
    public static function clearDefaultHeaders()
    {
        return Request::create('default')->clearDefaultHeaders();
    }

    /**
     * Set curl options to send on every request
     *
     * @param array $options options array
     * @return array
     */
    public static function curlOpts($options)
    {
        return Request::create('default')->curlOpts($options);
    }

    /**
     * Set a new default header to send on every request
     *
     * @param string $name header name
     * @param string $value header value
     * @return string
     */
    public static function curlOpt($name, $value)
    {
        return Request::create('default')->curlOpt($name, $value);
    }

    /**
     * Clear all the default headers
     */
    public static function clearCurlOpts()
    {
        return Request::create('default')->clearCurlOpts();
    }

    /**
     * Set a cookie string for enabling cookie handling
     *
     * @param string $cookie
     */
    public static function cookie($cookie)
    {
        Request::create('default')->cookie($cookie);
    }

    /**
     * Set a cookie file path for enabling cookie handling
     *
     * $cookieFile must be a correct path with write permission
     *
     * @param string $cookieFile - path to file for saving cookie
     */
    public static function cookieFile($cookieFile)
    {
        Request::create('default')->cookieFile($cookieFile);
    }

    /**
     * Set authentication method to use
     *
     * @param string $username authentication username
     * @param string $password authentication password
     * @param integer $method authentication method
     */
    public static function auth($username = '', $password = '', $method = CURLAUTH_BASIC)
    {
        Request::create('default')->auth($username, $password, $method);
    }

    /**
     * Set proxy to use
     *
     * @param string $address proxy address
     * @param integer $port proxy port
     * @param integer $type (Available options for this are CURLPROXY_HTTP, CURLPROXY_HTTP_1_0 CURLPROXY_SOCKS4, CURLPROXY_SOCKS5, CURLPROXY_SOCKS4A and CURLPROXY_SOCKS5_HOSTNAME)
     * @param bool $tunnel enable/disable tunneling
     */
    public static function proxy($address, $port = 1080, $type = CURLPROXY_HTTP, $tunnel = false)
    {
        Request::create('default')->proxy($address, $port, $type, $tunnel);
    }

    /**
     * Set proxy authentication method to use
     *
     * @param string $username authentication username
     * @param string $password authentication password
     * @param integer $method authentication method
     */
    public static function proxyAuth($username = '', $password = '', $method = CURLAUTH_BASIC)
    {
        Request::create('default')->proxyAuth($username, $password, $method);
    }

    /**
     * Send a GET request to a URL
     *
     * @param string $url URL to send the GET request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public static function get($url, $headers = [], $parameters = null)
    {
        return Request::create('default')->get($url, $headers, $parameters);
    }

    /**
     * Send a HEAD request to a URL
     * @param string $url URL to send the HEAD request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public static function head($url, $headers = [], $parameters = null)
    {
        return Request::create('default')->head($url, $headers, $parameters);
    }

    /**
     * Send a OPTIONS request to a URL
     * @param string $url URL to send the OPTIONS request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public static function options($url, $headers = [], $parameters = null)
    {
        return Request::create('default')->options($url, $headers, $parameters);
    }

    /**
     * Send a CONNECT request to a URL
     * @param string $url URL to send the CONNECT request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public static function connect($url, $headers = [], $parameters = null)
    {
        return Request::create('default')->connect($url, $headers, $parameters);
    }

    /**
     * Send POST request to a URL
     * @param string $url URL to send the POST request to
     * @param array $headers additional headers to send
     * @param mixed $body POST body data
     * @return \Ws\Http\Response
     */
    public static function post($url, $headers = [], $body = null)
    {
        return Request::create('default')->post($url, $headers, $body);
    }

    /**
     * Send DELETE request to a URL
     * @param string $url URL to send the DELETE request to
     * @param array $headers additional headers to send
     * @param mixed $body DELETE body data
     * @return \Ws\Http\Response
     */
    public static function delete($url, $headers = [], $body = null)
    {
        return Request::create('default')->delete($url, $headers, $body);
    }

    /**
     * Send PUT request to a URL
     * @param string $url URL to send the PUT request to
     * @param array $headers additional headers to send
     * @param mixed $body PUT body data
     * @return \Ws\Http\Response
     */
    public static function put($url, $headers = [], $body = null)
    {
        return Request::create('default')->put($url, $headers, $body);
    }

    /**
     * Send PATCH request to a URL
     * @param string $url URL to send the PATCH request to
     * @param array $headers additional headers to send
     * @param mixed $body PATCH body data
     * @return \Ws\Http\Response
     */
    public static function patch($url, $headers = [], $body = null)
    {
        return Request::create('default')->patch($url, $headers, $body);
    }

    /**
     * Send TRACE request to a URL
     * @param string $url URL to send the TRACE request to
     * @param array $headers additional headers to send
     * @param mixed $body TRACE body data
     * @return \Ws\Http\Response
     */
    public static function trace($url, $headers = [], $body = null)
    {
        return Request::create('default')->trace($url, $headers, $body);
    }

}