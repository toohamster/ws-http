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

		$data = [];

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
				// 
				try
				{
					$httpResponse = self::doRequest($request, $task);

					output($httpResponse);
				}
				catch(GlobalException $ex)
				{
					output($ex->getMessage());
				}
				
				break;
			}
		}

	}

	public static function doRequest(TaskRequest $request, Task $task)
	{
		if ($request->isDelay()) return null;

		output($request->name, $request->id);

		$httpRequest = HttpRequest::create();
		if ( $request->authorization )
		{
			$httpRequest->auth( $task->body->varReplace($request->authorization['type']),
				$task->body->varReplace($request->authorization['user']),
				$task->body->varReplace($request->authorization['password']));
		}

		switch ($request->timeout[0])
		{
			case 's':
				$httpRequest->timeout($task->body->varReplace($request->timeout[1]));
				break;
			case 'ms':
				$httpRequest->timeoutMs($task->body->varReplace($request->timeout[1]));
				break;
		}

		if ($request->proxy)
		{
			$httpRequest->proxy($task->body->varReplace($request->proxy['address']),
				$task->body->varReplace($request->proxy['port']),
				$task->body->varReplace($request->proxy['type']),
				$task->body->varReplace($request->proxy['tunnel']));

			if (!empty($request->proxy['auth']))
			{
				$httpRequest->proxyAuth($task->body->varReplace($request->proxy['auth']['user']), 
					$task->body->varReplace($request->proxy['auth']['password']),
					$task->body->varReplace($request->proxy['auth']['method']));
			}
		}

		$httpResponse = $httpRequest->send($request->method, 
			$task->body->varReplace($request->url),
			$task->body->varReplace($request->data),
			$task->body->varReplace($request->headers));

		return $httpResponse;
	}

}