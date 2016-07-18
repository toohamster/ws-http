<?php
/*
|--------------------------------------------------------------------------
| Register The Composer Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader
| for our application. We just need to utilize it! We'll require it
| into the script here so that we do not have to worry about the
| loading of any our classes "manually". Feels great to relax.
|
*/
require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Set The Default Timezone
|--------------------------------------------------------------------------
|
| Here we will set the default timezone for PHP. PHP is notoriously mean
| if the timezone is not explicitly set. This will be used by each of
| the PHP date and date-time functions throughout the application.
|
*/
date_default_timezone_set('PRC');

function assertEqual($var1,$var2)
{
	if ($var1 !== $var2)
		throw new Exception('Not Equal .');
}

function output($var, $tag='info')
{
	\Ws\Env::dump($var, $tag);
}

interface ITest
{
	public function run();
}

$tests = [
	'Ws.Http.ATest',
];

foreach ($tests as $test)
{

	$php = str_ireplace('.', DIRECTORY_SEPARATOR, $test) . '.php';
	require __DIR__.'/' . $php;
	$ppp = explode('.', $test);
	$CLASS = end($ppp);

	if (class_exists($CLASS, false))
	{
		$run = new $CLASS();
		$run->run();
	}
	
}
