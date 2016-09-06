<?php namespace Ws\Http\Automated;

use Ws\Http\Automated\Task;
use Ws\Http\Automated\Task\Request AS TaskRequest;
use \Exception as GlobalException;
use Ws\Http\Exception as HttpException;
use Ws\Http\Request as HttpRequest;
use Ws\Http\Response as HttpResponse;
use Ws\Http\Request\Body as HttpRequestBody;

/**
 * 脚本执行器
 */
class Bootstrap
{

	public static function run()
	{

	}

	public static function runTask(Task $task)
	{
		output($task->name, $task->id);

		$result = [];

		$requests = $task->body->requests;
		foreach ($requests as $request)
		{
			if ($request->isDelay())
			{
				if ($request->delay > 0)
				{
					sleep($request->delay);	
				}				
			}
			else
			{
				$result[] = self::doRequest($request, $task->type);
			}
		}

	}

	public static function doRequest(TaskRequest $request, $taskType)
	{
		if ($request->isDelay()) return null;

		output($request->name, $request->id);

		$httpRequest = HttpRequest::create();
		try
		{
			if ( $request->authorization )
			{
				$httpRequest->auth($request->authorization['type'],
					$request->authorization['user'],
					$request->authorization['password']);
			}

			switch ($request->timeout[0])
			{
				case 's':
					$httpRequest->timeout($request->timeout[1]);
					break;
				case 'ms':
					$httpRequest->timeoutMs($request->timeout[1]);
					break;
			}

			if ($request->proxy)
			{
				$httpRequest->proxy($request->proxy['address'],
					$request->proxy['port'],
					$request->proxy['type'],
					$request->proxy['tunnel']);

				if (!empty($request->proxy['auth']))
				{
					$httpRequest->proxyAuth($request->proxy['auth']['user'], 
						$request->proxy['auth']['password'],
						$request->proxy['auth']['method']);
				}
			}

			$httpResponse = $httpRequest->send($request->method, 
				$request->url,
				$request->data,
				$request->headers);

			output($httpResponse->curl_info, $httpResponse->code);
		}
		catch(GlobalException $ex)
		{
			output($ex->getMessage());
		}

		// 释放内存
		$httpRequest = null;
		unset($httpRequest);
	}

}