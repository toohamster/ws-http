<?php

use \Exception as GlobalException;
use Ws\Http\Exception as HttpException;
use Ws\Http\Method as HttpMethod;
use Ws\Http\ARequest as AHttpRequest;
use Ws\Http\Request as HttpRequest;
use Ws\Http\Response as HttpResponse;
use Ws\Http\Request\Body as HttpRequestBody;

class Autohome implements ITest
{
	public function run()
	{
		output(__METHOD__);
		$this->dir = __DIR__.'/../../data/autohome';
		$this->crawl();
	}

	public function crawl()
	{
		output(__METHOD__);
		$httpRequest = HttpRequest::create();
		// 1 的车型 有164条, 11的话 可以查出 242条
		$url = "http://www.autohome.com.cn/ashx/AjaxIndexCarFind.ashx?type=1";
		$httpResponse = $httpRequest->get($url);
		$filename = $this->dir . '/branditems.json';

		$raw_body = self::gbk2utf8($httpResponse->raw_body);
		file_put_contents($filename, $raw_body);

		$data = json_decode($raw_body, true);
		if ( !empty($data) && $data['returncode'] == 0 )
		{
			foreach ( $data['result']['branditems'] as $branditem )
			{
				if ( !empty($branditem['id']) )
				{
					$branditemId = $branditem['id'];
					$url = "http://www.autohome.com.cn/ashx/AjaxIndexCarFind.ashx?type=3&value={$branditemId}";
					$httpResponse = $httpRequest->get($url);

					$filename = $this->dir . "/branditem-{$branditemId}.json";
					$raw_body = self::gbk2utf8($httpResponse->raw_body);
					file_put_contents($filename, $raw_body);
					sleep(1);

					$data001 = json_decode($raw_body, true);
					if ( !empty($data001) && $data001['returncode'] == 0 )
					{
						foreach ( $data001['result']['factoryitems'] as $factoryitem )
						{
							if ( !empty($factoryitem['id']) )
							{
								$factoryitemId = $factoryitem['id'];
								if ( !empty($factoryitem['seriesitems']) )
								{
									foreach ( $factoryitem['seriesitems'] as $seriesitem )
									{
										if ( !empty($seriesitem['id']) )
										{
											$seriesitemId = $seriesitem['id'];
											
											$url = "http://www.autohome.com.cn/ashx/AjaxIndexCarFind.ashx?type=5&value={$seriesitemId}";
											$httpResponse = $httpRequest->get($url);

											$filename = $this->dir . "/branditem-{$branditemId}-factoryitem-{$factoryitemId}-seriesitem-{$seriesitemId}.json";
											$raw_body = self::gbk2utf8($httpResponse->raw_body);
											file_put_contents($filename, $raw_body);
											sleep(1);

											$data002 = json_decode($raw_body, true);

											$this->oilAndCars($data002,$httpRequest);

										}
									}
									
								}
							}
						}
					}

				}
			}
		}

	}

	public function oilAndCars($data, $httpRequest)
	{

		if ( !empty($data) && $data['returncode'] == 0 )
		{
			foreach ( $data['result']['yearitems'] as $yearitem )
			{
				if ( !empty($yearitem['id']) )
				{
					$yearitemId = $yearitem['id'];
					foreach ( $yearitem['specitems'] as $specitem )
					{
						if ( !empty($specitem['id']) )
						{
							$specitemId = $specitem['id'];
							// 处理油价
							$url = "http://www.autohome.com.cn/ashx/ajaxoil.ashx?type=spec&specId={$specitemId}";

							$httpResponse = $httpRequest->get($url);

							$filename = $this->dir . "/oil-{$specitemId}.json";
							$raw_body = self::gbk2utf8($httpResponse->raw_body);
							file_put_contents($filename, $raw_body);
							sleep(1);

							// 找车
							// http://www.autohome.com.cn/spec/18658/#pvareaid=10006
						}
					}

				}
			}
		}
	}

	private static function gbk2utf8($content)
	{
		return iconv("GBK", "UTF-8", $content);
	}

}