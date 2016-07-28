<?php namespace Ws\Http;

use Ws\Http\Watcher\Exception as Exception;

class Watcher
{

	/**
	 * Create Watcher instance with httpResponse
	 * 
	 * @param  \Ws\Http\Response $response
	 * @return \Ws\Http\Watcher
	 */
	public static function create(Response $response)
	{
		return new self($response);
	}

	private function __construct(Response $response)
	{
		$this->response = $response;
	}

	public function setResponse(Response $response)
	{
		$this->response = $response;

		return $this;
	}

	public function assertTotalTimeLessThan($assertedTime)
    {
        $totalTime = $this->response->curl_info['total_time'];

        if (floatval($assertedTime) < floatval($totalTime)) {
            throw new Exception("Asserted total time '$assertedTime' is less than '$totalTime'.");
        }

        return $this;
    }

	public function assertStatusCode($statusCode)
	{
		$httpCode = $this->response->code;
        if (intval($statusCode) !== $httpCode) {
            throw new Exception("Asserted status code '$statusCode' does not equal response status code '$httpCode'.");
        }

        return $this;
    }

    public function assertHeadersExist(array $assertedHeaders = [])
    {
    	$headers = & $this->response->headers;
        foreach ($assertedHeaders as $header) {
            if (!isset($headers[$header])) {
                throw new Exception("Asserted header '$header' is not set.");
            }
        }

        return $this;
    }

    public function assertHeaders(array $assertedHeaders = [])
    {
        if (isAssoc($assertedHeaders))
        {
        	$headers = & $this->response->headers;

            foreach ($assertedHeaders as $k => $v) {
                if (!array_key_exists($k, $headers)) {
                    throw new Exception("Asserted header '$k' is not set.");
                }

                if (is_array($headers[$k])) {
                    if (!in_array($v, $headers[$k])) {
                        throw new Exception("Asserted header '$k' exists, but the response header value '$v' is not equal.");
                    }
                } else {
                    if ($v !== $headers[$k]) {
                        throw new Exception("Asserted header '$k=$v' does not equal response header '$k={$headers[$k]}'.");
                    }
                }
            }
        }
        else {
            $this->assertHeadersExist($assertedHeaders);
        }

        return $this;
    }

	public function assertBody($assertedBody, $useRegularExpression = false)
	{
        $body = & $this->response->raw_body;

        if ($assertedBody === 'IS_EMPTY') {
            if ($body === false || $body === "") {
                return $this;
            } else {
                throw new Exception("Response body is not empty.");
            }
        }

        if ($assertedBody === 'IS_VALID_JSON') {
            if (json_decode($body) === null) {
                throw new Exception("Response body is invalid JSON.");
            }

            return $this;
        }

        if ($useRegularExpression) {
            if (!@preg_match($assertedBody, $body)) {
                throw new Exception("Asserted body '$assertedBody' does not match response body of '$body'.");
            }
        } else {
            if (strpos($assertedBody, $body)) {
                throw new Exception("Asserted body '$assertedBody' does not equal response body of '$body'.");
            }
        }

        return $this;
    }

    public function assertBodyJson($asserted, $onNotEqualVarExport = false)
    {
        $body = json_decode($this->response->raw_body);

        if ($body === null) {
            throw new Exception("Response body is invalid JSON.");
        }

        if ($asserted != $body) {
            $errorMessage = "Asserted body does not equal response body.";
            if ($onNotEqualVarExport) {
                $errorMessage .= "\n\n--------------- ASSERTED BODY ---------------\n" . var_export($asserted, true) .
                                 "\n\n--------------- RESPONSE BODY ---------------\n" . var_export($body, true) . "\n\n";
            }
            throw new Exception($errorMessage);
        }

        return $this;
    }

    public function assertBodyJsonFile($assertedJsonFile, $onNotEqualPrintJson = false)
    {
        if (!file_exists($assertedJsonFile)) {
            throw new Exception("Asserted JSON file '$assertedJsonFile' does not exist.");
        }

        $asserted = file_get_contents($assertedJsonFile);
        if (json_decode($asserted) === null) {
            throw new Exception("Asserted JSON file is invalid JSON.");
        }

        $body = $this->response->raw_body;

        if (json_decode($body) === null) {
            throw new Exception("Response body is invalid JSON.");
        }

        $asserted = prettyPrintJson($asserted);
        $body = prettyPrintJson($body);

        if ($asserted != $body) {
            $errorMessage = "Asserted JSON file does not equal response body.";
            if ($onNotEqualPrintJson) {
                $errorMessage .= "\n\n--------------- ASSERTED JSON FILE ---------------\n" . $asserted .
                                 "\n\n--------------- RESPONSE BODY ---------------\n" . $body . "\n\n";
            }
            throw new Exception($errorMessage);
        }

        return $this;
    }

}

function isAssoc($array)
{
    return (bool) count(array_filter(array_keys($array), 'is_string'));
}

function prettyPrintJson($json)
{
    $result = '';
    $level = 0;
    $prev_char = '';
    $in_quotes = false;
    $ends_line_level = null;
    $json_length = strlen($json);

    for ($i = 0; $i < $json_length; $i++) {
        $char = $json[$i];
        $new_line_level = null;
        $post = "";
        if ($ends_line_level !== null) {
            $new_line_level = $ends_line_level;
            $ends_line_level = null;
        }
        if ($char === '"' && $prev_char != '\\') {
            $in_quotes = !$in_quotes;
        } else {
            if (!$in_quotes) {
                switch ($char) {
                    case '}':
                    case ']':
                        $level--;
                        $ends_line_level = null;
                        $new_line_level = $level;
                        break;

                    case '{':
                    case '[':
                        $level++;
                    case ',':
                        $ends_line_level = $level;
                        break;

                    case ':':
                        $post = " ";
                        break;

                    case " ":
                    case "\t":
                    case "\n":
                    case "\r":
                        $char = "";
                        $ends_line_level = $new_line_level;
                        $new_line_level = null;
                        break;
                }
            }
        }
        if ($new_line_level !== null) {
            $result .= "\n" . str_repeat("\t", $new_line_level);
        }
        $result .= $char . $post;
        $prev_char = $char;
    }

    return $result;
}