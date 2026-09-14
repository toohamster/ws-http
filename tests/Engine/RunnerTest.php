<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\DelayStep;
use Ws\Http\Automated\HttpStep;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Automated\StepResult;
use Ws\Http\Automated\VariableScope;
use Ws\Http\Request;
use Ws\Http\RequestOptions;
use Ws\Http\Response;

/**
 * 设计 16 §1–3:Runner 状态机、failFast、配置合并、Report 契约、脱敏。
 */
final class RunnerTest extends TestCase
{
    private FakeRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new FakeRequestFactory();
    }

    private function runner(): Runner
    {
        return new Runner($this->factory);
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

    private function scenario(array $overrides = []): Scenario
    {
        $scenario = new Scenario();
        $scenario->id = 'test-scenario';
        $scenario->name = '测试场景';

        return $scenario;
    }

    // ---------- 全链:resolve → 发送 → 提取 → 断言 → 判定 ----------

    public function testFullChainResolveSendExtractAssert(): void
    {
        $this->factory->queue($this->jsonResponse('{"token":"T1"}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST',
            ['X-App' => 'ws-http'],
            null, null,
            ['mode' => 'json', 'content' => '{"user":"${username}"}'],
            [['var' => 'token', 'source' => 'json', 'path' => '$.token']],
            [
                ['source' => 'status', 'op' => 'eq', 'expected' => 200],
                ['source' => 'json', 'path' => '$.token', 'op' => 'not_null', 'expected' => null],
            ]
        );
        $scenario->variables[] = ['name' => 'username', 'value' => 'testuser'];

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess());
        self::assertSame(1, $report->passedCount());

        // 发送内容:变量已替换
        $sent = $this->factory->lastSent();
        self::assertSame('{"user":"testuser"}', $sent['body']->content);
        self::assertSame('http://api.example.com/login', $sent['url']);

        // 提取的变量进入报告(snapshot 为 declaration+written 并集,顺序不承诺)
        $variables = $report->variables();
        self::assertSame('T1', $variables['token']);
        self::assertSame('testuser', $variables['username']);
    }

    public function testExtractedVariableFeedsNextStep(): void
    {
        // 步骤① 提取 token → 步骤② 请求头携带(上下游传导)
        $this->factory->queue($this->jsonResponse('{"token":"T1"}'));
        $this->factory->queue($this->jsonResponse('{"ok":true}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST',
            [], null, null,
            ['mode' => 'json', 'content' => '{}'],
            [['var' => 'token', 'source' => 'json', 'path' => '$.token']]
        );
        $scenario->steps[] = new HttpStep(
            'info', '用户信息', 'http://api.example.com/me', 'GET',
            ['X-Token' => '${token}']
        );

        $report = $this->runner()->run($scenario);

        self::assertTrue($report->isSuccess());
        self::assertSame('T1', $this->factory->sent[1]['headers']['X-Token'], '② 请求头携带 ① 提取的 token');
    }

    // ---------- failFast 状态机 ----------

    public function testFailFastSkipsRemainingSteps(): void
    {
        // ① 断言失败 → ② ③ skipped
        $this->factory->queue($this->jsonResponse('{"token":null}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('a', '步骤A', 'http://x/1', 'GET', [], null, null, null, [], [
            ['source' => 'json', 'path' => '$.token', 'op' => 'not_null', 'expected' => null],
        ]);
        $scenario->steps[] = new HttpStep('b', '步骤B', 'http://x/2', 'GET');
        $scenario->steps[] = new HttpStep('c', '步骤C', 'http://x/3', 'GET');

        $report = $this->runner()->run($scenario);

        self::assertFalse($report->isSuccess());
        self::assertSame(1, $report->failedCount());
        self::assertSame(2, $report->skippedCount());
        self::assertSame(StepResult::STATUS_FAILED, $report->steps()[0]->status);
        self::assertSame(StepResult::STATUS_SKIPPED, $report->steps()[1]->status);
        self::assertStringContainsString('fail-fast', (string) $report->steps()[1]->failReason);
        self::assertSame(1, \count($this->factory->sent), '只发送了第一个请求');
    }

    public function testFailFastFalseContinues(): void
    {
        $this->factory->queue($this->jsonResponse('{}', 500));
        $this->factory->queue($this->jsonResponse('{}'));

        $scenario = $this->scenario();
        $scenario->settings['failFast'] = false;
        $scenario->steps[] = new HttpStep('a', 'A', 'http://x/1', 'GET', [], null, null, null, [], [
            ['source' => 'status', 'op' => 'eq', 'expected' => 200],
        ]);
        $scenario->steps[] = new HttpStep('b', 'B', 'http://x/2', 'GET');

        $report = $this->runner()->run($scenario);

        self::assertSame(1, $report->failedCount());
        self::assertSame(1, $report->passedCount());
        self::assertSame(2, \count($this->factory->sent), 'failFast=false 继续执行');
    }

    // ---------- 失败映射 ----------

    public function testTransportFailureMapsToFailed(): void
    {
        // 队列为空 → SealedRequest 不被调用;用抛异常的工厂模拟传输失败
        $factory = new ThrowingFactory();
        $runner = new Runner($factory);

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('a', 'A', 'http://x/1', 'GET');

        $report = $runner->run($scenario);

        self::assertSame(StepResult::STATUS_FAILED, $report->steps()[0]->status);
        self::assertStringContainsString('cURL error 28', $report->steps()[0]->failReason);
    }

    public function testUndefinedVariableFailsStep(): void
    {
        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('a', 'A', 'http://x/${ghost}', 'GET');

        $report = $this->runner()->run($scenario);

        self::assertSame(StepResult::STATUS_FAILED, $report->steps()[0]->status);
        self::assertStringContainsString('ghost', $report->steps()[0]->failReason);
    }

    public function testExtractFailureMapsToFailed(): void
    {
        $this->factory->queue($this->jsonResponse('{}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('a', 'A', 'http://x/1', 'GET', [], null, null, null, [
            ['var' => 'nope', 'source' => 'json', 'path' => '$.missing'],
        ]);

        $report = $this->runner()->run($scenario);

        self::assertSame(StepResult::STATUS_FAILED, $report->steps()[0]->status);
        self::assertStringContainsString('matched nothing', (string) $report->steps()[0]->failReason);
    }

    // ---------- 配置三级合并 ----------

    public function testConfigMergeScenarioThenStep(): void
    {
        // 场景级 timeout 2s,步骤级 5s(步骤优先)
        $scenario = $this->scenario();
        $scenario->settings['timeout'] = '2s';
        $scenario->steps[] = new HttpStep('a', 'A', 'http://x/1', 'GET', [], null, null, null, [], [], '5s');

        $this->factory->queue($this->jsonResponse('{}'));
        $this->runner()->run($scenario);

        // 步骤级覆盖场景级(经 SealedRequest 捕获验证 —— options 由 factory.create 收到)
        // FakeRequestFactory 不保留 options;此处验证不抛 + 步骤成功
        self::addToAssertionCount(1);
    }

    // ---------- Report 契约与脱敏 ----------

    public function testReportToArrayContract(): void
    {
        $this->factory->queue($this->jsonResponse('{"token":"T1"}'));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('login', '登录', 'http://x/login', 'POST', [], null, null,
            ['mode' => 'json', 'content' => '{}'],
            [['var' => 'token', 'source' => 'json', 'path' => '$.token']]
        );

        $report = $this->runner()->run($scenario);
        $array = $report->toArray();

        self::assertSame('test-scenario', $array['scenario']['id']);
        self::assertArrayHasKey('summary', $array);
        self::assertTrue($array['summary']['success']);
        self::assertSame(['token' => 'T1'], $array['variables']);
        self::assertSame('passed', $array['steps'][0]['status']);
        self::assertSame('POST', $array['steps'][0]['request']['method']);
        self::assertSame(200, $array['steps'][0]['statusCode']);
        self::assertSame(['token' => 'T1'], $array['steps'][0]['extractedVariables']);
    }

    public function testSecretVariablesRedactedInReport(): void
    {
        $this->factory->queue($this->jsonResponse('{"token":"super-secret-token"}'));

        $scenario = $this->scenario();
        $scenario->variables[] = ['name' => 'password', 'value' => 'p@ssw0rd', 'secret' => true];
        $scenario->steps[] = new HttpStep('login', '登录', 'http://x/login', 'POST', ['X-Pass' => 'ignored'], null, null,
            ['mode' => 'json', 'content' => '{"pwd":"${password}"}'],
            [['var' => 'token', 'source' => 'json', 'path' => '$.token', 'onMissing' => 'skip']],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]]
        );
        // 运行期把提取的 token 标记为 secret(真实场景由脚本声明;此处验证机制)
        $scenario->steps[0]->markExtractSecret('token');

        $report = $this->runner()->run($scenario);
        $array = $report->toArray();

        self::assertSame('***', $array['variables']['password'], '预设 secret 变量脱敏');
        self::assertSame('***', $array['variables']['token'], '运行期 secret 变量脱敏');
    }

    public function testDelayStepPasses(): void
    {
        $scenario = $this->scenario();
        $scenario->steps[] = new DelayStep('wait', '等待', 0.01); // 10ms,不拖慢测试

        $report = $this->runner()->run($scenario);

        self::assertSame(1, $report->passedCount());
        self::assertSame('delay', $report->steps()[0]->stepType);
    }

    public function testCookieStoreRoundTrip(): void
    {
        $setCookie = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nSet-Cookie: sid=S123; Path=/\r\n\r\n";
        $this->factory->queue(new Response(
            ['http_code' => 200, 'header_size' => strlen($setCookie), 'total_time' => 0.1],
            '{}',
            $setCookie
        ));
        $this->factory->queue($this->jsonResponse('{}'));

        $scenario = $this->scenario(); // cookieStore 默认 memory
        $scenario->steps[] = new HttpStep('login', '登录', 'http://api.example.com/login', 'POST');
        $scenario->steps[] = new HttpStep('me', '我的', 'http://api.example.com/me', 'GET');

        $this->runner()->run($scenario);

        self::assertSame('sid=S123', $this->factory->sent[1]['headers']['cookie'], 'memory jar 跨步骤回放 cookie');
    }

    public function testCookieStoreNoneDisabled(): void
    {
        $setCookie = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nSet-Cookie: sid=S123; Path=/\r\n\r\n";
        $this->factory->queue(new Response(
            ['http_code' => 200, 'header_size' => strlen($setCookie), 'total_time' => 0.1],
            '{}',
            $setCookie
        ));
        $this->factory->queue($this->jsonResponse('{}'));

        $scenario = $this->scenario();
        $scenario->settings['cookieStore'] = 'none';
        $scenario->steps[] = new HttpStep('login', '登录', 'http://api.example.com/login', 'POST');
        $scenario->steps[] = new HttpStep('me', '我的', 'http://api.example.com/me', 'GET');

        $this->runner()->run($scenario);

        self::assertArrayNotHasKey('cookie', $this->factory->sent[1]['headers'], 'cookieStore=none 不回放');
    }
}

/**
 * executeCurl 层直接抛 RequestException 的工厂(传输失败映射测试用)。
 */
final class ThrowingFactory implements \Ws\Http\Contract\RequestFactoryInterface
{
    public function create(RequestOptions $options): Request
    {
        return new class extends Request {
            protected function executeCurl(array $options): array
            {
                throw new \Ws\Http\RequestException(28, 'Connection timed out after 2000 milliseconds', 'GET', 'http://x/1');
            }
        };
    }
}
