<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\AutomatedException;
use Ws\Http\Automated\Report;
use Ws\Http\Automated\ScenarioOutput;
use Ws\Http\Automated\ScenarioParser;

/**
 * design/27 O1:outputs 白名单(V19)+ ScenarioOutput 出口。
 *
 * 钉住:V19 各拒绝态 402(非数组/重名/非法名/不可产出);提取键序 = 声明序;
 * 缺席键不出现;outputs 空/未声明 → 630;secret 脱敏继承 Report 形态。
 */
final class ScenarioOutputTest extends TestCase
{
    // ---------- V19 校验 ----------

    public function testOutputsParsedAndCarriedOnScenario(): void
    {
        $scenario = $this->parse([
            'id'        => 'get-weather',
            'name'      => '取天气',
            'outputs'   => ['city', 'temp'],
            'variables' => [['name' => 'cityCode', 'value' => '440100']],
            'steps'     => [$this->weatherStep()],
        ]);

        self::assertSame(['city', 'temp'], $scenario->outputs);
    }

    public function testV19RejectsNonArrayAndNonString(): void
    {
        $this->assertV19Rejects(['outputs' => 'city'], '/outputs');
        $this->assertV19Rejects(['outputs' => []], '/outputs');
        $this->assertV19Rejects(['outputs' => ['city', 42]], '/outputs/1');
    }

    public function testV19RejectsDuplicateAndIllegalNames(): void
    {
        $this->assertV19Rejects(['outputs' => ['city', 'city']], '/outputs/1', 'duplicate');
        $this->assertV19Rejects(['outputs' => ['1bad']], '/outputs/0', 'does not match');
    }

    public function testV19RejectsNonProducibleName(): void
    {
        // temp 拼错成 temp2:variables 与 extract 都产不出 → 拒(防拼错静默返回空)
        $this->assertV19Rejects(
            ['outputs' => ['city', 'temp2']],
            '/outputs/1',
            'not producible'
        );
    }

    public function testV19AcceptsPauseVarAsProducible(): void
    {
        $scenario = $this->parse([
            'id'      => 'pause-out',
            'name'    => '人工取值',
            'outputs' => ['approval'],
            'steps'   => [['type' => 'pause', 'id' => 'p1', 'name' => '审批', 'var' => 'approval', 'from' => 'stdin']],
        ]);

        self::assertSame(['approval'], $scenario->outputs);
    }

    public function testOutputsComposableWithIterate(): void
    {
        // outputs 与 settings.iterate 正交可组合(design/27 §2)
        $scenario = $this->parse([
            'id'        => 'batch-out',
            'name'      => '批量取数',
            'outputs'   => ['city'],
            'datasets'  => ['codes' => [['code' => '1'], ['code' => '2']]],
            'settings'  => ['iterate' => 'codes'],
            'variables' => [['name' => 'code', 'value' => '']],
            'steps'     => [$this->weatherStep()],
        ]);

        self::assertSame(['city'], $scenario->outputs);
        self::assertSame('codes', $scenario->settings['iterate']);
    }

    // ---------- ScenarioOutput 出口 ----------

    public function testExtractKeepsDeclarationOrderAndOmitsMissing(): void
    {
        $report = $this->reportWithVariables(['temp' => '26℃', 'city' => '广州', 'noise' => 'x']);

        $result = (new ScenarioOutput())->extract($report, ['city', 'temp']);

        self::assertSame(['city' => '广州', 'temp' => '26℃'], $result); // 键序 = 声明序,noise 不入
    }

    public function testExtractEmptyOutputsThrows630(): void
    {
        $report = $this->reportWithVariables(['city' => '广州']);

        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(630);
        (new ScenarioOutput())->extract($report, []);
    }

    public function testExtractSecretVariableIsRedacted(): void
    {
        // Runner 对 Report::variables 已做 Redactor;结果集继承脱敏形态(取数口非旁路)
        $report = $this->reportWithVariables(['token' => '***']);

        $result = (new ScenarioOutput())->extract($report, ['token']);

        self::assertSame(['token' => '***'], $result);
    }

    public function testExtractMissingKeyOmitted(): void
    {
        // onMissing=fail 的缺值键不会出现在结果集(完整性以脚本成功为前提,§3)
        $report = $this->reportWithVariables(['city' => '广州']);

        $result = (new ScenarioOutput())->extract($report, ['city', 'temp']);

        self::assertSame(['city' => '广州'], $result);
    }

    // ---------- 工具 ----------

    private function parse(array $data): \Ws\Http\Automated\Scenario
    {
        return (new ScenarioParser())->parseArray($data);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function assertV19Rejects(array $overrides, string $expectedPointer, string $expectedNeedle = ''): void
    {
        $data = array_merge([
            'id'        => 'get-weather',
            'name'      => '取天气',
            'variables' => [['name' => 'cityCode', 'value' => '440100']],
            'steps'     => [$this->weatherStep()],
        ], $overrides);

        try {
            $this->parse($data);
            self::fail(sprintf('expected V19 rejection at %s', $expectedPointer));
        } catch (AutomatedException $e) {
            self::assertSame(402, $e->getCode());
            self::assertStringContainsString($expectedPointer, $e->getMessage());
            if ($expectedNeedle !== '') {
                self::assertStringContainsString($expectedNeedle, $e->getMessage());
            }
        }
    }

    private function weatherStep(): array
    {
        return [
            'type'    => 'http',
            'id'      => 's1',
            'name'    => '查天气',
            'url'     => 'https://api.example.com/weather?code=${cityCode}',
            'method'  => 'GET',
            'extract' => [
                ['var' => 'city', 'source' => 'json', 'path' => '$.city'],
                ['var' => 'temp', 'source' => 'json', 'path' => '$.data.temp'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function reportWithVariables(array $variables): Report
    {
        $report = new Report('s', 'S');
        $report->finalize($variables, 1.0);

        return $report;
    }
}
