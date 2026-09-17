<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\HttpStep;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\ScenarioParser;
use Ws\Http\Automated\StepResult;
use Ws\Http\Response;

/**
 * 端到端:v2 夜点场景 fixture(design/14 §6)经 Parser → Runner 全链(mock HTTP)。
 */
final class BookingFlowScenarioTest extends TestCase
{
    private function factoryWithBookingResponses(): FakeRequestFactory
    {
        $factory = new FakeRequestFactory();

        // ① login
        $factory->queue($this->response('{"token":"T1","result":0}', 200, "Set-Cookie: sid=ABC; Path=/\r\n"));
        // ② userinfo
        $factory->queue($this->response('{"result":0,"openid":"O1","userid":"U1"}'));
        // ③ search
        $factory->queue($this->response('{"result":0,"total":2,"list":[{"xktvid":"K9"}]}'));
        // ④ wait(不产生响应)
        // ⑤ submit
        $factory->queue($this->response('{"result":0,"orderNo":"NO123"}'));

        return $factory;
    }

    private function response(string $rawBody, int $code = 200, string $extraHeaders = ''): \Ws\Http\Response
    {
        $headers = sprintf("HTTP/1.1 %d OK\r\nContent-Type: application/json\r\n%s\r\n", $code, $extraHeaders);

        return new \Ws\Http\Response(
            ['http_code' => $code, 'header_size' => strlen($headers), 'total_time' => 0.1],
            $rawBody,
            $headers
        );
    }

    public function testFullBookingFlowEndToEnd(): void
    {
        $factory = $this->factoryWithBookingResponses();
        $scenario = (new ScenarioParser())->parseFile(__DIR__ . '/../fixtures/scenarios/booking-flow.json');

        $report = (new Runner($factory))->run($scenario);

        self::assertTrue($report->isSuccess(), '全链应通过: ' . json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));
        self::assertSame(5, $report->passedCount());
        self::assertSame(0, $report->skippedCount(), '修复 B4 回归:全部步骤被执行');

        // 变量传导验证:登录 token → 搜索 → 提交
        self::assertSame('T1', $report->variables()['token']);
        self::assertSame('K9', $report->variables()['ktvId']);
        self::assertSame('sid=ABC; Path=/', $report->variables()['loginCookie']);

        // 请求内容验证:② 携带 token;⑤(submit=第4个http请求)携带 token + cookie + ktvId
        self::assertSame('T1', $factory->sent[1]['headers']['X-KTV-User-Token']);
        self::assertStringContainsString('K9', $factory->sent[3]['body']->content);
        self::assertSame('sid=ABC; Path=/', $factory->sent[3]['headers']['cookie']);

        // ④ 延时步骤执行(顺序保持)
        self::assertSame('delay', $report->steps()[3]->stepType);
    }

    public function testBookingFlowFailsAtSearchWhenTotalZero(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->response('{"token":"T1"}', 200, "Set-Cookie: sid=ABC; Path=/\r\n"));
        $factory->queue($this->response('{"result":0}'));
        // ③ search:total=0 → gt 0 断言失败 → failFast → ⑤ skipped
        $factory->queue($this->response('{"result":0,"total":0,"list":[]}'));

        $scenario = (new ScenarioParser())->parseFile(__DIR__ . '/../fixtures/scenarios/booking-flow.json');

        $report = (new Runner($factory))->run($scenario);

        self::assertFalse($report->isSuccess());
        self::assertSame(2, $report->passedCount());
        self::assertSame(1, $report->failedCount());
        self::assertSame(2, $report->skippedCount(), 'submit 被跳过');
        // total=0 → list=[] → json 提取先失败(onMissing=fail 优先于断言)
        self::assertStringContainsString('matched nothing', (string) $report->steps()[2]->failReason);
    }

    public function testScenarioReportArrayShapeStable(): void
    {
        $factory = $this->factoryWithBookingResponses();
        $scenario = (new ScenarioParser())->parseFile(__DIR__ . '/../fixtures/scenarios/booking-flow.json');

        $array = (new Runner($factory))->run($scenario)->toArray();

        // JSON 报告契约(design/16 §3.2 + design/22 §4 meta 可选字段)结构快照
        self::assertSame(['id', 'name'], array_keys($array['scenario']));
        self::assertSame(['passed', 'failed', 'skipped', 'success'], array_keys($array['summary']));
        self::assertCount(5, $array['steps']);
        self::assertSame(
            ['id', 'name', 'type', 'status', 'failReason', 'request', 'statusCode', 'durationMs', 'responseExcerpt', 'assertions', 'warnings', 'extractedVariables', 'meta'],
            array_keys($array['steps'][0])
        );
    }
}
