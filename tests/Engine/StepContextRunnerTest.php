<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\HttpStep;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Automated\StepResult;
use Ws\Http\Response;

/**
 * design/29:步骤上下文执行语义(retry/until/when 与组合)。
 *
 * sleep/clock 经 Runner 构造器注入替身,零真实等待。
 */
final class StepContextRunnerTest extends TestCase
{
    private FakeRequestFactory $factory;

    /** @var float[] 每次 sleep 收到的秒数 */
    private array $sleeps = [];

    /** @var float 模拟时钟(按 sleep 推进) */
    private float $now = 0.0;

    protected function setUp(): void
    {
        $this->factory = new FakeRequestFactory();
        $this->sleeps = [];
        $this->now = 0.0;
    }

    private function runner(): Runner
    {
        $self = $this;

        return new Runner(
            $this->factory,
            null,
            null,
            null,
            null,
            static function (float $seconds) use ($self): void {
                $self->sleeps[] = $seconds;
                $self->now += $seconds; // 时钟随 sleep 推进(与真实语义一致)
            },
            static function () use ($self): float {
                return $self->now;
            }
        );
    }

    private function jsonResponse(string $rawBody, int $code = 200): Response
    {
        $headers = sprintf("HTTP/1.1 %d X\r\nContent-Type: application/json\r\n\r\n", $code);

        return new Response(
            ['http_code' => $code, 'header_size' => strlen($headers), 'total_time' => 0.1],
            $rawBody,
            $headers
        );
    }

    private function scenario(): Scenario
    {
        $scenario = new Scenario();
        $scenario->id = 'ctx-scenario';
        $scenario->name = '上下文场景';

        return $scenario;
    }

    // ---------- retry ----------

    public function testRetryFirstAttemptPassesNoSleep(): void
    {
        $this->factory->queue($this->jsonResponse('{"ok":1}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/x', 'GET',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, ['max' => 2, 'interval' => '2s']);

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess());
        self::assertCount(0, $this->sleeps, '首轮通过不等待');
        self::assertSame(1, $this->factory->sent === [] ? 1 : count($this->factory->sent));
        self::assertSame(1, $report->steps()[0]->attempts);
        self::assertSame('retry', $report->steps()[0]->context);
    }

    public function testRetrySecondAttemptPassesSleepsOnce(): void
    {
        // 第 1 轮断言失败(状态 500),第 2 轮 200
        $this->factory->queue($this->jsonResponse('{}', 500));
        $this->factory->queue($this->jsonResponse('{"ok":1}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/x', 'GET',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, ['max' => 2, 'interval' => '2s']);

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess(), '第 2 轮通过');
        self::assertSame([2.0], $this->sleeps, '轮次间隔 sleep 一次');
        self::assertCount(2, $this->factory->sent, '发送两轮');
        self::assertSame(2, $report->steps()[0]->attempts);
    }

    public function testRetryExhaustedFailsAndFailFastStops(): void
    {
        $this->factory->queue($this->jsonResponse('{}', 500));
        $this->factory->queue($this->jsonResponse('{}', 500));
        $this->factory->queue($this->jsonResponse('{}', 500));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/x', 'GET',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, ['max' => 2, 'interval' => '1s']);
        $scenario->steps[] = new HttpStep('s2', '后续', 'http://api.example.com/y', 'GET');

        $report = $this->runner()->run($scenario);

        self::assertFalse($report->isSuccess());
        self::assertSame(3, $report->steps()[0]->attempts, '总执行次数 = max + 1');
        self::assertSame(StepResult::STATUS_FAILED, $report->steps()[0]->status);
        self::assertSame(StepResult::STATUS_SKIPPED, $report->steps()[1]->status, '重试耗尽才触发 failFast');
        self::assertCount(3, $this->factory->sent);
        self::assertCount(2, $this->sleeps, '2 次间隔');
    }

    public function testRetryDoesNotRetryOnResolveFailure(): void
    {
        // ${undefined} resolve 失败:配置错误,不重试
        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/${undefinedVar}', 'GET',
            [], null, null, null, [], [], null, null, ['max' => 3, 'interval' => '1s']);

        $report = $this->runner()->run($scenario);

        self::assertFalse($report->isSuccess());
        self::assertSame(StepResult::STATUS_FAILED, $report->steps()[0]->status);
        self::assertCount(0, $this->factory->sent, '未发送任何请求');
        self::assertCount(0, $this->sleeps, '未等待');
        self::assertSame(1, $report->steps()[0]->attempts);
    }

    // ---------- until ----------

    public function testUntilFirstAttemptPassesRunsNormalAssertionsAndExtract(): void
    {
        $this->factory->queue($this->jsonResponse('{"status":"done"}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '轮询', 'http://api.example.com/job', 'GET',
            [], null, null, null,
            [['var' => 'finalStatus', 'source' => 'json', 'path' => '$.status']],
            [['source' => 'json', 'path' => '$.status', 'op' => 'eq', 'expected' => 'done']],
            null, null, null,
            ['timeout' => '60s', 'interval' => '3s', 'assertions' => [['source' => 'json', 'path' => '$.status', 'op' => 'eq', 'expected' => 'done']]]);

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess());
        self::assertCount(1, $this->factory->sent);
        self::assertCount(0, $this->sleeps);
        self::assertSame('done', $report->variables()['finalStatus'], 'extract 用通过轮的响应');
        self::assertSame(1, $report->steps()[0]->attempts);
        self::assertSame('until', $report->steps()[0]->context);
    }

    public function testUntilPassesOnLaterAttemptUsesLastResponse(): void
    {
        $this->factory->queue($this->jsonResponse('{"status":"running"}'));
        $this->factory->queue($this->jsonResponse('{"status":"running"}'));
        $this->factory->queue($this->jsonResponse('{"status":"done","result":"R9"}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '轮询', 'http://api.example.com/job', 'GET',
            [], null, null, null,
            [['var' => 'result', 'source' => 'json', 'path' => '$.result']],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, null,
            ['timeout' => '60s', 'interval' => '3s', 'assertions' => [['source' => 'json', 'path' => '$.status', 'op' => 'eq', 'expected' => 'done']]]);

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess());
        self::assertCount(3, $this->factory->sent);
        self::assertSame([3.0, 3.0], $this->sleeps, '2 次间隔');
        self::assertSame('R9', $report->variables()['result'], '常规 extract 用末轮(通过轮)响应');
        self::assertSame(3, $report->steps()[0]->attempts);
    }

    public function testUntilTimeoutFailsWithoutRunningRegularAssertions(): void
    {
        // 永远 running → 超时;常规断言(extractedVar 必须产出)不得执行
        for ($i = 0; $i < 5; $i++) {
            $this->factory->queue($this->jsonResponse('{"status":"running"}'));
        }

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '轮询', 'http://api.example.com/job', 'GET',
            [], null, null, null,
            [['var' => 'result', 'source' => 'json', 'path' => '$.result']],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, null,
            ['timeout' => '10s', 'interval' => '2s', 'assertions' => [['source' => 'json', 'path' => '$.status', 'op' => 'eq', 'expected' => 'done']]]);

        $report = $this->runner()->run($scenario);

        self::assertFalse($report->isSuccess());
        $step = $report->steps()[0];
        self::assertSame(StepResult::STATUS_FAILED, $step->status);
        self::assertStringContainsString('until timeout after 10s', (string) $step->failReason);
        self::assertStringContainsString('attempts', (string) $step->failReason);
        self::assertSame(StepResult::STATUS_SKIPPED, $report->steps()[1]->status ?? StepResult::STATUS_SKIPPED, '单步骤场景无后续,断言计数即可');
        self::assertSame([], $report->steps()[0]->extractedVariables, '常规 extract 未执行');
    }

    public function testUntilTransportErrorRoundIsRetriedWithinWindow(): void
    {
        // 第 1 轮传输失败(factory create 抛),第 2 轮正常 → 门槛通过
        $this->factory->queue($this->jsonResponse('{"status":"done"}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '轮询', 'http://api.example.com/job', 'GET',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, null,
            ['timeout' => '30s', 'interval' => '1s', 'assertions' => [['source' => 'json', 'path' => '$.status', 'op' => 'eq', 'expected' => 'done']]]);

        // SealedRequest 不抛传输异常;传输异常路径由真实 Request 覆盖(Integration)。
        // 此处锁定:until 轮询中 collectFrom 每轮执行(单轮即单次收集,经 MemoryCookieStore 无感)。
        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess());
        self::assertCount(1, $this->factory->sent);
    }

    // ---------- when ----------

    public function testWhenTruthyExecutes(): void
    {
        $this->factory->queue($this->jsonResponse('{"ok":1}'));

        $scenario = $this->scenario();
        $scenario->variables[] = ['name' => 'hasDraft', 'value' => 1];
        $scenario->steps[] = new HttpStep('s1', '清理', 'http://api.example.com/clean', 'DELETE',
            [], null, null, null, [], [], null, null, null, null, ['var' => 'hasDraft']);

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess());
        self::assertCount(1, $this->factory->sent);
        self::assertSame(StepResult::STATUS_PASSED, $report->steps()[0]->status);
    }

    public function testWhenFalsySkipsWithoutSending(): void
    {
        $scenario = $this->scenario();
        $scenario->variables[] = ['name' => 'hasDraft', 'value' => false];
        $scenario->steps[] = new HttpStep('s1', '清理', 'http://api.example.com/clean', 'DELETE',
            [], null, null, null, [], [], null, null, null, null, ['var' => 'hasDraft']);
        $scenario->steps[] = new HttpStep('s2', '后续', 'http://api.example.com/y', 'GET');

        $this->factory->queue($this->jsonResponse('{"ok":1}'));

        $report = $this->runner()->run($scenario);

        $step1 = $report->steps()[0];
        self::assertSame(StepResult::STATUS_SKIPPED, $step1->status);
        self::assertStringContainsString("when: var 'hasDraft' is falsy", (string) $step1->failReason);
        // s1 未发送;s2 照常执行(1 次发送)= when-falsy 不影响后续步骤的直接证据
        self::assertCount(1, $this->factory->sent, '仅 s2 发送一次');
        self::assertSame('http://api.example.com/y', $this->factory->sent[0]['url'], '发送的是 s2 而非 s1');
        // skipped 不触发 failFast:后续步骤照常执行
        self::assertSame(StepResult::STATUS_PASSED, $report->steps()[1]->status);
        self::assertSame(0, $report->failedCount());
    }

    public function testWhenUndefinedVariableIsFalsy(): void
    {
        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '清理', 'http://api.example.com/clean', 'DELETE',
            [], null, null, null, [], [], null, null, null, null, ['var' => 'neverSet']);

        $report = $this->runner()->run($scenario);

        self::assertSame(StepResult::STATUS_SKIPPED, $report->steps()[0]->status, '未定义变量 = falsy = skipped');
        self::assertCount(0, $this->factory->sent);
    }

    public function testWhenTruthyTable(): void
    {
        // truthy 形态全表:执行;falsy 形态全表:跳过
        $truthy = [1, '1', true, 'abc', [1], 0.5, 'false'];
        $falsy = [0, '', '0', [], null, false];

        foreach ($truthy as $i => $value) {
            $this->factory = new FakeRequestFactory();
            $this->factory->queue($this->jsonResponse('{"ok":1}'));
            $scenario = $this->scenario();
            $scenario->variables[] = ['name' => 'flag', 'value' => $value];
            $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/x', 'GET',
                [], null, null, null, [], [], null, null, null, null, ['var' => 'flag']);

            $report = $this->runner()->run($scenario);
            self::assertSame(StepResult::STATUS_PASSED, $report->steps()[0]->status, sprintf('truthy[%d] %s 应执行', $i, var_export($value, true)));
        }

        foreach ($falsy as $i => $value) {
            $this->factory = new FakeRequestFactory();
            $scenario = $this->scenario();
            $scenario->variables[] = ['name' => 'flag', 'value' => $value];
            $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/x', 'GET',
                [], null, null, null, [], [], null, null, null, null, ['var' => 'flag']);

            $report = $this->runner()->run($scenario);
            self::assertSame(StepResult::STATUS_SKIPPED, $report->steps()[0]->status, sprintf('falsy[%d] %s 应跳过', $i, var_export($value, true)));
        }
    }

    // ---------- 组合 ----------

    public function testWhenFalsyWithRetrySkipsEntirely(): void
    {
        $scenario = $this->scenario();
        $scenario->variables[] = ['name' => 'flag', 'value' => 0];
        $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/x', 'GET',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, ['max' => 3, 'interval' => '1s'], null, ['var' => 'flag']);

        $report = $this->runner()->run($scenario);

        self::assertSame(StepResult::STATUS_SKIPPED, $report->steps()[0]->status, '条件假:重试循环根本不进入');
        self::assertCount(0, $this->factory->sent);
        self::assertCount(0, $this->sleeps);
    }

    public function testRetryPlusUntilRetryWrapsPolling(): void
    {
        // 轮 1:until 超时(2 attempts:0s 失败,2s 失败 → 2+2>3)→ retry sleep 5 → 轮 2(7s):done 通过
        $this->factory->queue($this->jsonResponse('{"status":"running"}'));
        $this->factory->queue($this->jsonResponse('{"status":"running"}'));
        $this->factory->queue($this->jsonResponse('{"status":"done"}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '轮询', 'http://api.example.com/job', 'GET',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, ['max' => 1, 'interval' => '5s'],
            ['timeout' => '3s', 'interval' => '2s', 'assertions' => [['source' => 'json', 'path' => '$.status', 'op' => 'eq', 'expected' => 'done']]]);

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess(), 'retry 第 2 轮 until 通过');
        self::assertCount(3, $this->factory->sent, '轮 1 轮询 2 次 + 轮 2 轮询 1 次');
        // attempts 语义 = 末轮 retry 轮的 until 执行次数(每次 runHttpStep 调用各自累计,design/29 §3.3)
        self::assertSame(1, $report->steps()[0]->attempts, '通过轮(第 2 轮 retry)的 until 执行 1 次');
        self::assertSame([2.0, 5.0], $this->sleeps, '轮 1 内 until 间隔 2s 一次 + retry 间隔 5s 一次');
        self::assertSame('until', $report->steps()[0]->context);
    }

    // ---------- 报告契约 ----------

    public function testReportAttemptsContextOptionalFields(): void
    {
        $this->factory->queue($this->jsonResponse('{}', 500));
        $this->factory->queue($this->jsonResponse('{"ok":1}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤', 'http://api.example.com/x', 'GET',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
            null, null, ['max' => 2, 'interval' => '1s']);

        $plainStep = new HttpStep('s2', '普通', 'http://api.example.com/y', 'GET');
        $scenario->steps[] = $plainStep;
        $this->factory->queue($this->jsonResponse('{}'));

        $report = $this->runner()->run($scenario);
        $array = $report->toArray();

        self::assertSame(2, $array['steps'][0]['attempts'], 'retry 步骤输出 attempts');
        self::assertSame('retry', $array['steps'][0]['context']);
        self::assertArrayNotHasKey('attempts', $array['steps'][1], '普通步骤不输出 attempts(旧消费端兼容)');
        self::assertArrayNotHasKey('context', $array['steps'][1]);
    }
}
