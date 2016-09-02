<?php namespace Ws\Http\Automated\Task;

use Ws\Http\Automated\Exception as Exception;
use Ws\Http\Method;

/**
 * 任务内的请求
 */
class Request
{

	/**
	 * id
	 * @var string
	 */
	private $id;

	/**
	 * 名称
	 * @var string
	 */
	private $name;

	/**
	 * 类型: 1. 请求 ; 2. 暂停
	 *
	 * 暂停类型时, 停留的时间为秒
	 * 
	 * @var string
	 */
	private $type;

	/**
	 * 解析生成 Task\Request 对象
	 * 
	 * @param  array $params
	 * @return \Ws\Http\Automated\Task\Request
	 */
	public static function parse($params = [])
	{
		$obj = new Request();

		$obj->id = $params['id'];
		$obj->type = (int) $params['type'];

		switch ($obj->type) {
			case 1:
				$obj->name = $params['name'];
				$obj->url = $params['url'];
				$obj->protocol = $params['protocol'];
				$obj->method = strtoupper(trim($params['method']));				

				$obj->setAuthorization($params['authorization']);
				$obj->setProxy($params['proxy']);

				// 设置默认请求头
				$obj->headers['Content-Type'] = 'text/plain';

				// 处理请求数据
				switch ($obj->method)
				{
					// 这两种类型无需对参数做处理
					case Method::GET:
					case Method::HEAD:
						foreach( (array) $params['data'] as $item )
						{
							if ( 'text' == $item['type'] )
							{
								$obj->data[ $item['key'] ] = $item['value'];
							}
							else
							{
								// 暂未实现
							}
						}
						break;

					// 只有 PUT,POST,DELETE,OPTIONS,PATCH 支持内容体
					case Method::POST:
					case Method::PUT:
					case Method::DELETE:
					case Method::OPTIONS:
						$dataMode = strtolower( trim($params['dataMode']) );
						switch ($dataMode) {
							case 'params':
								foreach( (array) $params['data'] as $item )
								{
									if ( 'text' == $item['type'] )
									{
										$obj->data[ $item['key'] ] = $item['value'];
									}
									else if ( 'file' == $item['type'] )
									{
										$obj->headers['Content-Type'] = 'multipart/form-data';
										// file 内容暂不支持
									}
								}
								break;
							case 'urlencoded':
								$obj->headers['Content-Type'] = 'application/x-www-form-urlencoded';
								foreach( (array) $params['data'] as $item )
								{
									if ( 'text' == $item['type'] )
									{
										$obj->data[ $item['key'] ] = $item['value'];
									}
								}
								break;

							case 'raw-json':
								$obj->headers['Content-Type'] = 'application/json';
								$obj->data = $params['data']['value'];
								break;
							case 'raw-xml':
								$obj->headers['Content-Type'] = 'application/xml';
								$obj->data = $params['data']['value'];
								break;
							case 'raw-textxml':
								$obj->headers['Content-Type'] = 'text/xml';
								$obj->data = $params['data']['value'];
								break;
							case 'raw-html':
								$obj->headers['Content-Type'] = 'text/html';
								$obj->data = $params['data']['value'];
								break;							
							case 'raw-text':
								$obj->headers['Content-Type'] = 'text/plain';
								$obj->data = $params['data']['value'];
								break;
							case 'raw-binary':
							default:
								throw new Exception("Not supported request `dataMode`: {$dataMode}");
								break;
						}

						break;
					default:
						throw new Exception("Not supported request `method`: {$obj->method}");
						break;
				}

				// 设置请求头
				foreach( (array) $params['headers'] as $key => $val )
				{
					$obj->headers[$key] = $val;
				}

				// 设置断言规则
				
				// 设置变量提取规则

				break;
			case 2:
				$obj->delay = (int) $params['delay'];
				break;
			default:
				throw new Exception("Unknown request `type`: {$obj->type}");
				break;
		}

		return $obj;
	}

	/**
	 * 类型是否为请求间隔
	 * 
	 * @return boolean
	 */
	public function isDelay()
	{
		return 2 == $this->type;
	}

	public function setAuthorization($authorization=[])
	{

		$this->authorization = false;

		if (!empty($authorization) && empty($authorization['type']))
		{
			$type = trim($authorization['type']);
			if ( 'Basic' == $type )
			{
				$this->authorization = [
					'type'	=> 'Basic',
					'user'	=> trim($authorization['user']),
					'password'	=> $authorization['password'],
				];
			}
			else
			{
				$this->headers['Authorization'] = trim($authorization['body']);
			}			
		}
	}

	public function setProxy($proxy=[])
	{
		$this->proxy = false;

		if (!empty($proxy) && empty($proxy['type']))
		{
			
			$this->proxy = [
				'address'	=> trim($proxy['address']),
				'port'	=> intval($proxy['port']),
				'tunnel'	=> $proxy['tunnel'],
			];

			$type = strtolower( trim($proxy['type']) );

			switch ($type) {
				case 'http':
					$this->proxy['type'] = CURLPROXY_HTTP;
					break;
				case 'http1.0':
					if ( !defined('CURLPROXY_HTTP_1_0') )
					{
						throw new Exception("Unknown proxy `type`: {$type}");
					}
					$this->proxy['type'] = CURLPROXY_HTTP_1_0;
					break;
				case 'socks4':
					$this->proxy['type'] = CURLPROXY_SOCKS4;
					break;
				case 'socks4a':
					if ( !defined('CURLPROXY_SOCKS4A') )
					{
						throw new Exception("Unknown proxy `type`: {$type}");
					}
					$this->proxy['type'] = CURLPROXY_SOCKS4A;
					break;
				case 'socks5':
					$this->proxy['type'] = CURLPROXY_SOCKS5;
					break;
				case 'socks5.hostname':
					if ( !defined('CURLPROXY_SOCKS5_HOSTNAME') )
					{
						throw new Exception("Unknown proxy `type`: {$type}");
					}
					$this->proxy['type'] = CURLPROXY_SOCKS5_HOSTNAME;
					break;
				default:
					throw new Exception("Unknown proxy `type`: {$type}");
					break;
			}
		}
	}

}