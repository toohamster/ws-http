<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\AutomatedException;
use Ws\Http\Automated\ScenarioParser;

/**
 * design/29 §4:步骤上下文校验 V20–V23。
 */
final class StepContextParserTest extends TestCase
{
    private ScenarioParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ScenarioParser();
    }

    private function validScenario(): array
    {
        return [
            'id'   => 'sc-ctx',
            'name' => '上下文场景',
            'steps' => [
                ['type' => 'http', 'id' => 's1', 'name' => '步骤1',
                 'url' => 'http://api.example.com/ping', 'method' => 'GET'],
            ],
        ];
    }

    private function assertScenarioError(array $data, int $code, string $needle, string $note = ''): void
    {
        try {
            $this->parser->parseArray($data);
            self::fail(sprintf('expected AutomatedException(%d) %s', $code, $note));
        } catch (AutomatedException $e) {
            self::assertSame($code, $e->getCode(), $note . ' | ' . $e->getMessage());
            self::assertStringContainsString($needle, $e->getMessage(), $note);
        }
    }

    // ---------- 正向解析 ----------

    public function testRetryParsesWithDefaults(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['retry'] = ['max' => 2];

        $scenario = $this->parser->parseArray($data);
        $retry = $scenario->steps[0]->retry();

        self::assertSame(['max' => 2, 'interval' => '1s'], $retry, 'interval 默认 1s');
    }

    public function testUntilParsesWithDefaults(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['until'] = [
            'timeout' => '60s',
            'assertions' => [['source' => 'json', 'path' => '$.status', 'op' => 'eq', 'expected' => 'done']],
        ];

        $scenario = $this->parser->parseArray($data);
        $until = $scenario->steps[0]->until();

        self::assertSame('60s', $until['timeout']);
        self::assertSame('2s', $until['interval'], 'interval 默认 2s');
        self::assertCount(1, $until['assertions']);
    }

    public function testWhenParses(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['when'] = ['var' => 'hasDraft'];

        $scenario = $this->parser->parseArray($data);

        self::assertSame(['var' => 'hasDraft'], $scenario->steps[0]->when());
    }

    public function testAllThreeCombinedParse(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['retry'] = ['max' => 1, 'interval' => '500ms'];
        $data['steps'][0]['until'] = [
            'timeout' => '30s', 'interval' => '1s',
            'assertions' => [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
        ];
        $data['steps'][0]['when'] = ['var' => 'enabled'];

        $scenario = $this->parser->parseArray($data);
        $step = $scenario->steps[0];

        self::assertSame(1, $step->retry()['max']);
        self::assertSame('30s', $step->until()['timeout']);
        self::assertSame('enabled', $step->when()['var']);
    }

    // ---------- V20:retry 结构 ----------

    public function testV20RetryMaxBelowRangeRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['retry'] = ['max' => 0];

        $this->assertScenarioError($data, 402, '/steps/0/retry/max');
    }

    public function testV20RetryMaxAboveRangeRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['retry'] = ['max' => 6];

        $this->assertScenarioError($data, 402, '[1, 5]');
    }

    public function testV20RetryMaxNonIntRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['retry'] = ['max' => '2'];

        $this->assertScenarioError($data, 402, 'integer');
    }

    public function testV20RetryIntervalOverLimitRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['retry'] = ['max' => 1, 'interval' => '61s'];

        $this->assertScenarioError($data, 402, '<= 60s');
    }

    public function testV20RetryUnknownFieldRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['retry'] = ['max' => 1, 'backoff' => true];

        $this->assertScenarioError($data, 402, 'unknown field');
    }

    // ---------- V21:until 结构 ----------

    public function testV21UntilTimeoutMissingRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['until'] = [
            'assertions' => [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
        ];

        $this->assertScenarioError($data, 402, 'timeout');
    }

    public function testV21UntilTimeoutOverLimitRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['until'] = [
            'timeout' => '301s',
            'assertions' => [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
        ];

        $this->assertScenarioError($data, 402, '<= 300s');
    }

    public function testV21UntilAssertionsEmptyRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['until'] = ['timeout' => '30s', 'assertions' => []];

        $this->assertScenarioError($data, 402, 'non-empty');
    }

    public function testV21UntilAssertionsInvalidPathRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['until'] = [
            'timeout' => '30s',
            'assertions' => [['source' => 'json', 'path' => '$..[', 'op' => 'eq', 'expected' => 1]],
        ];

        $this->assertScenarioError($data, 402, 'invalid JSONPath');
    }

    public function testV21UntilIntervalOverLimitRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['until'] = [
            'timeout' => '30s', 'interval' => '61s',
            'assertions' => [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
        ];

        $this->assertScenarioError($data, 402, '<= 60s');
    }

    // ---------- V22:when 结构 ----------

    public function testV22WhenUnknownFieldRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['when'] = ['var' => 'x', 'op' => 'eq'];

        $this->assertScenarioError($data, 402, 'unknown field');
    }

    public function testV22WhenInvalidVarNameRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['when'] = ['var' => '9bad'];

        $this->assertScenarioError($data, 402, 'naming rule');
    }

    public function testV22WhenUndeclaredVarAccepted(): void
    {
        // V22 与 V10 同策略:不校验变量是否已声明,运行期按 falsy 处理
        $data = $this->validScenario();
        $data['steps'][0]['when'] = ['var' => 'neverDeclared'];

        $scenario = $this->parser->parseArray($data);

        self::assertSame('neverDeclared', $scenario->steps[0]->when()['var']);
    }

    // ---------- V23:仅 http 步骤支持 ----------

    public function testV23DelayWithRetryRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0] = ['type' => 'delay', 'id' => 'd1', 'name' => '等待', 'duration' => '1s', 'retry' => ['max' => 1]];

        $this->assertScenarioError($data, 402, 'http only');
    }

    public function testV23PauseWithUntilRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0] = ['type' => 'pause', 'id' => 'p1', 'name' => '等待输入',
            'var' => 'otp', 'from' => 'stdin',
            'until' => ['timeout' => '10s', 'assertions' => [['source' => 'status', 'op' => 'eq', 'expected' => 200]]]];

        $this->assertScenarioError($data, 402, 'http only');
    }

    public function testV23DelayWithWhenRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0] = ['type' => 'delay', 'id' => 'd1', 'name' => '等待', 'duration' => '1s', 'when' => ['var' => 'x']];

        $this->assertScenarioError($data, 402, 'http only');
    }
}
