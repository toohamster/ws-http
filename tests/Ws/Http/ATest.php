<?php

use \Exception as GlobalException;
use Ws\Http\Exception as HttpException;
use Ws\Http\Method as HttpMethod;
use Ws\Http\Request as HttpRequest;
use Ws\Http\Response as HttpResponse;
use Ws\Http\Request\Body as HttpRequestBody;
use Ws\Http\Watcher as HttpWatcher;

class ATest implements ITest
{

	public function run()
	{
		output(__METHOD__);
		$this->authis();
	}

	private function authis()
	{
		try 
		{
			$httpResponse = HttpRequest::get("https://api.stripe.com");
			output($httpResponse);
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
			output( $ex->getMessage() , 'error');
		}
	}

}