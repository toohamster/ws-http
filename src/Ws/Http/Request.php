<?php namespace Ws\Http;

class Request
{
    private static $instances = [];

    private $cookie = null;
    private $cookieFile = null;
    private $curlOpts = [];
    private $defaultHeaders = [];
    private $handle = null;
    private $jsonOpts = [];
    private $socketTimeout = null;
    private $verifyPeer = true;
    private $verifyHost = true;
    private $verifyFile = null;

    private $auth = [
        'user' => '',
        'pass' => '',
        'method' => CURLAUTH_BASIC
    ];

    private $proxy = [
        'port' => false,
        'tunnel' => false,
        'address' => false,
        'type' => CURLPROXY_HTTP,
        'auth' => [
            'user' => '',
            'pass' => '',
            'method' => CURLAUTH_BASIC
        ]
    ];

    private function __construct()
    {
        $this->verifyFile = __DIR__ . '/_ssl/ca-bundle.crt';
    }

    /**
     * Get a Object
     *
     * @param string $id object identification, default auto build
     * @return \Ws\Http\Request
     */
    public static function create($id = null)
    {
        if ( empty($id) )
        {
            $id = md5(__METHOD__) . count(self::$instances); 
        }
        if ( empty(self::$instances[$id]) )
        {
            self::$instances[$id] = new self();  
        }
        return self::$instances[$id];
    }

    /**
     * Set JSON decode mode
     *
     * @param bool $assoc When TRUE, returned objects will be converted into associative arrays.
     * @param integer $depth User specified recursion depth.
     * @param integer $options Bitmask of JSON decode options. Currently only JSON_BIGINT_AS_STRING is supported (default is to cast large integers as floats)
     * @return array
     */
    public function jsonOpts($assoc = false, $depth = 512, $options = 0)
    {
        return $this->jsonOpts = [$assoc, $depth, $options];
    }

    /**
     * Verify SSL peer
     *
     * @param bool $enabled enable SSL verification, by default is true
     * @return bool
     */
    public function verifyPeer($enabled)
    {
        return $this->verifyPeer = $enabled;
    }

    /**
     * Verify SSL host
     *
     * @param bool $enabled enable SSL host verification, by default is true
     * @return bool
     */
    public function verifyHost($enabled)
    {
        return $this->verifyHost = $enabled;
    }

    /**
     * Verify SSL File
     *
     * @param string $file SSL verification file
     * @return string
     */
    public function verifyFile($file)
    {
        return $this->verifyFile = $file;
    }

    /**
     * Get Verify SSL File
     * 
     * @return string
     */
    public function getVerifyFile()
    {
        return $this->verifyFile;
    }

    /**
     * Set a timeout
     *
     * @param integer $seconds timeout value in seconds
     * @return integer
     */
    public function timeout($seconds)
    {
        return $this->socketTimeout = $seconds;
    }

    /**
     * Set default headers to send on every request
     *
     * @param array $headers headers array
     * @return array
     */
    public function defaultHeaders($headers)
    {
        return $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);
    }

    /**
     * Set a new default header to send on every request
     *
     * @param string $name header name
     * @param string $value header value
     * @return string
     */
    public function defaultHeader($name, $value)
    {
        return $this->defaultHeaders[$name] = $value;
    }

    /**
     * Clear all the default headers
     */
    public function clearDefaultHeaders()
    {
        return $this->defaultHeaders = [];
    }

    /**
     * Set curl options to send on every request
     *
     * @param array $options options array
     * @return array
     */
    public function curlOpts($options)
    {
        return $this->mergeCurlOptions($this->curlOpts, $options);
    }

    /**
     * Set a new default header to send on every request
     *
     * @param string $name header name
     * @param string $value header value
     * @return string
     */
    public function curlOpt($name, $value)
    {
        return $this->curlOpts[$name] = $value;
    }

    /**
     * Clear all the default headers
     */
    public function clearCurlOpts()
    {
        return $this->curlOpts = [];
    }

    /**
     * Set a cookie string for enabling cookie handling
     *
     * @param string $cookie
     */
    public function cookie($cookie)
    {
        $this->cookie = $cookie;
    }

    /**
     * Set a cookie file path for enabling cookie handling
     *
     * $cookieFile must be a correct path with write permission
     *
     * @param string $cookieFile - path to file for saving cookie
     */
    public function cookieFile($cookieFile)
    {
        $this->cookieFile = $cookieFile;
    }

    /**
     * Set authentication method to use
     *
     * @param string $username authentication username
     * @param string $password authentication password
     * @param integer $method authentication method
     */
    public function auth($username = '', $password = '', $method = CURLAUTH_BASIC)
    {
        $this->auth['user'] = $username;
        $this->auth['pass'] = $password;
        $this->auth['method'] = $method;
    }

    /**
     * Set proxy to use
     *
     * @param string $address proxy address
     * @param integer $port proxy port
     * @param integer $type (Available options for this are CURLPROXY_HTTP, CURLPROXY_HTTP_1_0 CURLPROXY_SOCKS4, CURLPROXY_SOCKS5, CURLPROXY_SOCKS4A and CURLPROXY_SOCKS5_HOSTNAME)
     * @param bool $tunnel enable/disable tunneling
     */
    public function proxy($address, $port = 1080, $type = CURLPROXY_HTTP, $tunnel = false)
    {
        $this->proxy['type'] = $type;
        $this->proxy['port'] = $port;
        $this->proxy['tunnel'] = $tunnel;
        $this->proxy['address'] = $address;
    }

    /**
     * Set proxy authentication method to use
     *
     * @param string $username authentication username
     * @param string $password authentication password
     * @param integer $method authentication method
     */
    public function proxyAuth($username = '', $password = '', $method = CURLAUTH_BASIC)
    {
        $this->proxy['auth']['user'] = $username;
        $this->proxy['auth']['pass'] = $password;
        $this->proxy['auth']['method'] = $method;
    }

    /**
     * Send a GET request to a URL
     *
     * @param string $url URL to send the GET request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public function get($url, $headers = [], $parameters = null)
    {
        return $this->send(Method::GET, $url, $parameters, $headers);
    }

    /**
     * Send a HEAD request to a URL
     * @param string $url URL to send the HEAD request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public function head($url, $headers = [], $parameters = null)
    {
        return $this->send(Method::HEAD, $url, $parameters, $headers);
    }

    /**
     * Send a OPTIONS request to a URL
     * @param string $url URL to send the OPTIONS request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public function options($url, $headers = [], $parameters = null)
    {
        return $this->send(Method::OPTIONS, $url, $parameters, $headers);
    }

    /**
     * Send a CONNECT request to a URL
     * @param string $url URL to send the CONNECT request to
     * @param array $headers additional headers to send
     * @param mixed $parameters parameters to send in the querystring
     * @return \Ws\Http\Response
     */
    public function connect($url, $headers = [], $parameters = null)
    {
        return $this->send(Method::CONNECT, $url, $parameters, $headers);
    }

    /**
     * Send POST request to a URL
     * @param string $url URL to send the POST request to
     * @param array $headers additional headers to send
     * @param mixed $body POST body data
     * @return \Ws\Http\Response
     */
    public function post($url, $headers = [], $body = null)
    {
        return $this->send(Method::POST, $url, $body, $headers);
    }

    /**
     * Send DELETE request to a URL
     * @param string $url URL to send the DELETE request to
     * @param array $headers additional headers to send
     * @param mixed $body DELETE body data
     * @return \Ws\Http\Response
     */
    public function delete($url, $headers = [], $body = null)
    {
        return $this->send(Method::DELETE, $url, $body, $headers);
    }

    /**
     * Send PUT request to a URL
     * @param string $url URL to send the PUT request to
     * @param array $headers additional headers to send
     * @param mixed $body PUT body data
     * @return \Ws\Http\Response
     */
    public function put($url, $headers = [], $body = null)
    {
        return $this->send(Method::PUT, $url, $body, $headers);
    }

    /**
     * Send PATCH request to a URL
     * @param string $url URL to send the PATCH request to
     * @param array $headers additional headers to send
     * @param mixed $body PATCH body data
     * @return \Ws\Http\Response
     */
    public function patch($url, $headers = [], $body = null)
    {
        return $this->send(Method::PATCH, $url, $body, $headers);
    }

    /**
     * Send TRACE request to a URL
     * @param string $url URL to send the TRACE request to
     * @param array $headers additional headers to send
     * @param mixed $body TRACE body data
     * @return \Ws\Http\Response
     */
    public function trace($url, $headers = [], $body = null)
    {
        return $this->send(Method::TRACE, $url, $body, $headers);
    }

    /**
     * This function is useful for serializing multidimensional arrays, and avoid getting
     * the 'Array to string conversion' notice
     * @param array|object $data array to flatten.
     * @param bool|string $parent parent key or false if no parent
     * @return array
     */
    public static function buildHTTPCurlQuery($data, $parent = false)
    {
        static $CFClassExist = null;
        if ( is_null($CFClassExist) )
        {
            $CFClassExist = class_exists('CURLFile', false);
        }

        $result = [];

        if (is_object($data)) {
            $data = get_object_vars($data);
        }

        foreach ($data as $key => $value) {
            if ($parent) {
                $new_key = sprintf('%s[%s]', $parent, $key);
            } else {
                $new_key = $key;
            }

            if ($CFClassExist && $value instanceof \CURLFile)
            {
                $result[$new_key] = $value;
            }
            else if (is_array($value) || is_object($value))
            {
                $result = array_merge($result, self::buildHTTPCurlQuery($value, $new_key));
            }
            else {
                $result[$new_key] = $value;
            }
        }

        return $result;
    }

    /**
     * Send a cURL request
     * @param string $method HTTP method to use
     * @param string $url URL to send the request to
     * @param mixed $body request body
     * @param array $headers additional headers to send
     * @throws \Ws\Http\Exception if a cURL error occurs
     * @return \Ws\Http\Response
     */
    public function send($method, $url, $body = null, $headers = [])
    {
        $this->handle = curl_init();

        if ($method !== Method::GET) {
			if ($method === Method::POST) {
				curl_setopt($this->handle, CURLOPT_POST, true);
			} else {
				curl_setopt($this->handle, CURLOPT_CUSTOMREQUEST, $method);
			}

            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $body);
        } elseif (is_array($body)) {
            if (strpos($url, '?') !== false) {
                $url .= '&';
            } else {
                $url .= '?';
            }

            $url .= urldecode(http_build_query(self::buildHTTPCurlQuery($body)));
        }

        $curl_base_options = [
            CURLOPT_URL => self::encodeUrl($url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_HTTPHEADER => $this->getFormattedHeaders($headers),
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            //CURLOPT_SSL_VERIFYHOST accepts only 0 (false) or 2 (true). Future versions of libcurl will treat values 1 and 2 as equals
            CURLOPT_SSL_VERIFYHOST => $this->verifyHost === false ? 0 : 2,
            // If an empty string, '', is set, a header containing all supported encoding types is sent
            CURLOPT_ENCODING => ''
        ];

        if ( $this->verifyPeer )
        {
            $curl_base_options[CURLOPT_CAINFO] = $this->getVerifyFile();
        }

        curl_setopt_array($this->handle, $this->mergeCurlOptions($curl_base_options, $this->curlOpts));

        if ($this->socketTimeout !== null) {
            curl_setopt($this->handle, CURLOPT_TIMEOUT, $this->socketTimeout);
        }

        if ($this->cookie) {
            curl_setopt($this->handle, CURLOPT_COOKIE, $this->cookie);
        }

        if ($this->cookieFile) {
            curl_setopt($this->handle, CURLOPT_COOKIEFILE, $this->cookieFile);
            curl_setopt($this->handle, CURLOPT_COOKIEJAR, $this->cookieFile);
        }

        if (!empty($this->auth['user'])) {
            curl_setopt_array($this->handle, [
                CURLOPT_HTTPAUTH    => $this->auth['method'],
                CURLOPT_USERPWD     => $this->auth['user'] . ':' . $this->auth['pass']
            ]);
        }

        if ($this->proxy['address'] !== false) {
            curl_setopt_array($this->handle, [
                CURLOPT_PROXYTYPE       => $this->proxy['type'],
                CURLOPT_PROXY           => $this->proxy['address'],
                CURLOPT_PROXYPORT       => $this->proxy['port'],
                CURLOPT_HTTPPROXYTUNNEL => $this->proxy['tunnel'],
                CURLOPT_PROXYAUTH       => $this->proxy['auth']['method'],
                CURLOPT_PROXYUSERPWD    => $this->proxy['auth']['user'] . ':' . $this->proxy['auth']['pass']
            ]);
        }

        $response   = curl_exec($this->handle);
        $error      = curl_error($this->handle);
        $info       = curl_getinfo($this->handle);

        curl_close($this->handle);

        if ($error) {
            throw new Exception($error);
        }

        // Split the full response in its headers and body
        $header_size = $info['header_size'];
        $header      = substr($response, 0, $header_size);
        $body        = substr($response, $header_size);

        return new Response($info, $body, $header, $this->jsonOpts);
    }

    public function getFormattedHeaders($headers)
    {
        $formattedHeaders = [];

        $combinedHeaders = array_change_key_case(array_merge($this->defaultHeaders, (array) $headers));

        foreach ($combinedHeaders as $key => $val) {
            $formattedHeaders[] = $this->getHeaderString($key, $val);
        }

        if (!array_key_exists('user-agent', $combinedHeaders)) {
            $formattedHeaders[] = 'user-agent: ws-http/1.0';
        }

        if (!array_key_exists('expect', $combinedHeaders)) {
            $formattedHeaders[] = 'expect:';
        }

        return $formattedHeaders;
    }

    private static function getArrayFromQuerystring($query)
    {
        $query = preg_replace_callback('/(?:^|(?<=&))[^=[]+/', function ($match) {
            return bin2hex(urldecode($match[0]));
        }, $query);

        parse_str($query, $values);

        return array_combine(array_map('hex2bin', array_keys($values)), $values);
    }

    /**
     * Ensure that a URL is encoded and safe to use with cURL
     * @param  string $url URL to encode
     * @return string
     */
    private static function encodeUrl($url)
    {
        $url_parsed = parse_url($url);

        $scheme = $url_parsed['scheme'] . '://';
        $host   = $url_parsed['host'];
        $port   = (isset($url_parsed['port']) ? $url_parsed['port'] : null);
        $path   = (isset($url_parsed['path']) ? $url_parsed['path'] : null);
        $query  = (isset($url_parsed['query']) ? $url_parsed['query'] : null);

        if ($query !== null) {
            $query = '?' . http_build_query(self::getArrayFromQuerystring($query));
        }

        if ($port && $port[0] !== ':') {
            $port = ':' . $port;
        }

        $result = $scheme . $host . $port . $path . $query;
        return $result;
    }

    private static function getHeaderString($key, $val)
    {
        $key = trim(strtolower($key));
        return $key . ': ' . $val;
    }

    /**
     * @param array $existing_options
     * @param array $new_options
     * @return array
     */
    private static function mergeCurlOptions(&$existing_options, $new_options)
    {
        $existing_options = $new_options + $existing_options;
        return $existing_options;
    }
}