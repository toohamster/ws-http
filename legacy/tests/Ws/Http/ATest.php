<?php

use \Exception as GlobalException;
use Ws\Http\Exception as HttpException;
use Ws\Http\Method as HttpMethod;
use Ws\Http\ARequest as AHttpRequest;
use Ws\Http\Request as HttpRequest;
use Ws\Http\Response as HttpResponse;
use Ws\Http\Request\Body as HttpRequestBody;
use Ws\Http\Watcher as HttpWatcher;

class ATest implements ITest
{

	public function run()
	{
		output(__METHOD__);
		$this->testAAA();
		// $this->testAuth();
		// $this->testGet();
		// $this->testPost();
		// $this->testJson();
		// $this->testJsonFile();
	}

	private function testAAA()
	{
		output(__METHOD__);
		try
		{
			$httpRequest = HttpRequest::create();
			$httpRequest->timeout(10);
			// $httpRequest->proxy('127.0.0.1','8888');

			$httpResponse = $httpRequest->get("http://117.121.26.105/admin/login",[
					'User-Agent'	=> 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:47.0) Gecko/20100101 Firefox/47.0',
				]);

			// $httpRequest->get("http://117.121.26.105/assets/application-e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855.css");
			// $httpRequest->get("http://117.121.26.105/assets/application-3be7df993ba49cb551697afa2cc2aa789f6b450516bdf1913f9d901b11cbb391.js");
			// $httpRequest->get("http://117.121.26.105/favicon.ico");

			output($httpResponse->headers);
			output($httpResponse->raw_body);

			$cookie = $httpResponse->headers['Set-Cookie'];
			$raw_body = $httpResponse->raw_body;
			// get token
			preg_match('<meta name="csrf-token" content="(.+)" />', $raw_body, $tt);

			$authenticity_token = $tt[1];

			// $cookie = str_ireplace('%3D', '=', $cookie);
			// $cookie = str_ireplace('; path=/; HttpOnly', '', $cookie);
			$httpRequest->cookie($cookie);
			$body = [
					// 'utf8'	=> '&#x2713;',
					// 'authenticity_token'	=> $authenticity_token,
					'login'	=> 'admin',
					'password'	=> 'lottery',
					// '_method'	=> 'post',
				];
			// 
			$httpResponse = $httpRequest->post("http://117.121.26.105/admin/login",[
					'Content-Type'	=> 'application/x-www-form-urlencoded',
					'Host'	=> '117.121.26.105',
					'Pragmal'	=> 'no-cache',
					'Upgrade-Insecure-Requests'	=> 1,
					'Cache-Control'	=> 'no-cache',
					'Origin'	=> 'http://117.121.26.105',
					'X-CSRF-Token'	=> $authenticity_token,
					'User-Agent'	=> 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:47.0) Gecko/20100101 Firefox/47.0',
					'Connection'	=> 'keep-alive',
					'Accept'	=> 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',

					'X-Requested-With'	=> 'XMLHttpRequest',
					'Referer'	=> 'http://117.121.26.105/admin/login',
					'Accept-Encoding'	=> 'gzip, deflate',
					'Accept-Language'	=> 'zh-CN,zh;q=0.8',
					// 'Cookie'	=> $cookie,
					
				], $body);
				

			output($httpResponse->headers);
			output($httpResponse->raw_body);
			
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__);
		}

	}

	private function testAuth()
	{
		output(__METHOD__);
		try
		{
			$httpRequest = HttpRequest::create();
			$httpRequest->auth("foo", "bar");
			$httpRequest->timeout(10);

			$httpResponse = $httpRequest->get("https://api.stripe.com");
			// output($httpResponse);
			$watcher = HttpWatcher::create($httpResponse);

			$watcher->assertStatusCode(401)
             ->assertHeadersExist(array(
                "Www-Authenticate"
             ))
             ->assertHeaders(array(
                "Server" => "nginx",
                "Cache-Control" => "no-cache, no-store"
             ))
             ->assertBody('IS_VALID_JSON');
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__);
		}
	}

	private function testGet()
	{
		output(__METHOD__);

		$httpRequest = HttpRequest::create();
		
		try 
		{
			output(__LINE__);
			$httpResponse = $httpRequest->get("https://freegeoip.net/csv/8.8.8.8");
			$watcher = HttpWatcher::create( $httpResponse );

			$watcher
	             ->assertStatusCode(200)
	             ->assertHeaders(array(
	                "Access-Control-Allow-Origin" => "*"
	             ))
	             ->assertBody('"8.8.8.8","US","United States","","","","","38.0000","-97.0000","",""');

		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__ . ':freegeoip.net');
		}
		
		try 
		{
			output(__LINE__);
			$httpResponse = $httpRequest->get("https://www.google.com");
	        $watcher->setResponse( $httpResponse )
		             ->assertStatusCode(200)
		             ->assertHeadersExist(array(
		                "X-Frame-Options"
		             ))
		             ->assertHeaders(array(
		                "Server" => "gws",
		                "Transfer-Encoding" => "chunked"
		             ))
		             ->assertBody("/<!doctype html>.*/", true);
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__ . ':google.com');
		}
		
		try 
		{
			output(__LINE__);
			$httpResponse = $httpRequest->get("https://api.github.com");
		    $watcher->setResponse( $httpResponse )
		             ->assertStatusCode(200)
		             ->assertHeadersExist(array(
		                "X-GitHub-Request-Id",
		                "ETag"
		             ))
		             ->assertHeaders(array(
		                "Server" => "GitHub.com"
		             ))
		             ->assertBody('IS_VALID_JSON')
		             ->assertTotalTimeLessThan(2);
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__ . ':github.com');
		}
		
		// æµè¯ä»£çåè½,æ¬å°ä½¿ç¨Lantern (http://127.0.0.1:8787)
		try 
		{
			output(__LINE__);
			$httpRequest->proxy('http://127.0.0.1', 8787);

			$httpResponse = $httpRequest->get("https://www.google.com");
	        $watcher->setResponse( $httpResponse )
		             ->assertStatusCode(200)
		             ->assertHeadersExist(array(
		                "X-Frame-Options"
		             ))
		             ->assertHeaders(array(
		                "Server" => "gws",
		                "Transfer-Encoding" => "chunked"
		             ))
		             ->assertBody("/<!doctype html>.*/", true);
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__ . ':google.com');
		}

	}

	private function testPost()
	{
		output(__METHOD__);
		try 
		{
			$httpRequest = HttpRequest::create();
			$httpRequest->timeout(30);
			// æµè¯çååå¨å¢å¤ æ¬å°ä½¿ç¨Lantern (http://127.0.0.1:8787)
			$httpRequest->proxy('http://127.0.0.1', 8787);

			$httpResponse = $httpRequest->post("https://api.balancedpayments.com/api_keys");
			// output($httpResponse);
			$watcher = HttpWatcher::create($httpResponse);

			$watcher
             ->assertStatusCode(201)
             ->assertHeadersExist(array(
                "X-Balanced-Host",
                "X-Balanced-Guru"
             ))
             ->assertHeaders(array(
                "Content-Type" => "application/json"
             ))
             ->assertBody('IS_VALID_JSON');
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__);
		}
	}

	private function testJson()
	{
		output(__METHOD__);
		try 
		{
			$expected = new stdClass();
		    $expected->ip = "8.8.8.8";
		    $expected->country_code = "US";
		    $expected->country_name = "United States";
		    $expected->region_code = "CA";
		    $expected->region_name = "California";
		    $expected->city = "Mountain View";
		    $expected->zip_code = "94040";
		    $expected->time_zone = "America/Los_Angeles";
		    $expected->latitude = 37.386000000000003;
		    $expected->longitude = -122.0838;
		    $expected->metro_code = 807;

		    $httpRequest = HttpRequest::create();

		    $httpResponse = $httpRequest->get("https://freegeoip.net/json/8.8.8.8");
			// output($httpResponse);
			$watcher = HttpWatcher::create($httpResponse);

			$watcher
		             ->assertStatusCode(200)
		             ->assertHeadersExist(array(
		                "Date"
		             ))
		             ->assertHeaders(array(
		                // "Access-Control-Allow-Origin" => "*"
		             ))
		             ->assertBodyJson($expected, true);
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__);
		}
	}

	private function testJsonFile()
	{
		output(__METHOD__);
		try 
		{
			$httpRequest = HttpRequest::create();

		    $httpResponse = $httpRequest->get("https://freegeoip.net/json/8.8.8.8");
			// output($httpResponse);
			$watcher = HttpWatcher::create($httpResponse);

			$watcher
             ->assertStatusCode(200)
             ->assertHeadersExist(array(
                "Content-Length"
             ))
             ->assertHeaders(array(
                // "Access-Control-Allow-Origin" => "*"
             ))
             ->assertBodyJsonFile(__DIR__ . "/_json/freegeoip.net.json", true);
		}
		catch(GlobalException $ex)
		{
			output( $ex->getMessage() , __METHOD__);
		}
	}

}