<?php

use Ws\Http\Automated\Task;

class Automated implements ITest
{
	public function run()
	{
		output(__METHOD__);
		$this->test();
	}

	public function test()
	{
		output(__METHOD__);
		$file = __DIR__ . "/_json/a.automated.json";

		$json = file_get_contents($file);

		$task = Task::parse($json);

		output($task);
	}

}