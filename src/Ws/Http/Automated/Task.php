<?php namespace Ws\Http\Automated;

use Ws\Http\Automated\Task\Body as TaskBody;

/**
 * 任务
 */
class Task
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
	 * 类型: 1. 关联业务 ; 2. 非关联业务
	 *
	 * 在 关联业务 中,只要一个请求处理失败,就会直接终止此次任务执行
	 * @var string
	 */
	private $type;

	/**
	 * 内容体
	 * @var \Ws\Http\Automated\Task\Body
	 */
	private $body;

	/**
	 * 解析字符串生成 Task 对象
	 * 
	 * @param  string $json
	 * @return \Ws\Http\Automated\Task
	 */
	public static function parse($json)
	{

		// 验证json格式

		$params = json_decode($json, true);

		$obj = new Task();
		$obj->id = $params['id'];
		$obj->name = $params['name'];
		$obj->type = (int) $params['type'];
		$obj->body = TaskBody::parse($params['body']);

		return $obj;
	}

}
