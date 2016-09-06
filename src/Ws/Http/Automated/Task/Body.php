<?php namespace Ws\Http\Automated\Task;

/**
 * 任务内容体
 */
class Body
{

	/**
	 * 自定义变量
	 * 
	 * @var map
	 */
	public $vars = [];

	/**
	 * 请求列表
	 * 
	 * @var map
	 */
	public $requests = [];

	/**
	 * 解析生成 Task\Body 对象
	 * 
	 * @param  array $params
	 * @return \Ws\Http\Automated\Task\Body
	 */
	public static function parse($params = [])
	{
		$obj = new Body();

		foreach( (array) $params['vars'] as $item )
		{
			$obj->setVar($item['name'], $item['value']);
		}

		foreach( (array) $params['requests'] as $item )
		{
			$obj->setRequest($item);
		}

		return $obj;
	}

	public function setVar($name, $value)
	{
		$this->vars[$name] = $value;
	}

	public function setRequest($request)
	{
		$this->requests[] = Request::parse($request);;
	}

}