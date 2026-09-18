<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\Dataset\BatchReport;
use Ws\Http\Automated\Dataset\RecordResult;
use Ws\Http\Automated\HttpStep;
use Ws\Http\Automated\Render\JsonRenderer;
use Ws\Http\Automated\Render\TextRenderer;
use Ws\Http\Automated\Report;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Tests\Engine\FakeRequestFactory;
use Ws\Http\Response;

/**
 * design/16 §5.1:渲染契约(TextRenderer/JsonRenderer,Report 与 BatchReport 两形态)。
 */
final class RenderTest extends TestCase
{
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
        $scenario->id = 'render-sc';
        $scenario->name = '渲染测试场景';

        return $scenario;
    }

    private function passedScenarioReport(): Report
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{}'));
        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤一', 'http://api.example.com/x', 'GET');

        return (new Runner($factory))->run($scenario);
    }

    public function testJsonRendererScenarioContract(): void
    {
        $json = (new JsonRenderer())->render($this->passedScenarioReport());

        $decoded = json_decode($json, true);
        self::assertSame('render-sc', $decoded['scenario']['id']);
        self::assertSame('passed', $decoded['steps'][0]['status']);
        self::assertStringEndsWith("\n", $json);
    }

    public function testTextRendererScenarioShape(): void
    {
        $text = (new TextRenderer())->render($this->passedScenarioReport());

        self::assertStringContainsString('Scenario 渲染测试场景 (render-sc)', $text);
        self::assertStringContainsString('✓ s1', $text);
        self::assertStringContainsString('Summary: 1 passed, 0 failed, 0 skipped', $text);
    }

    public function testTextRendererFailedStepShowsReason(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{}', 500));
        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤一', 'http://api.example.com/x', 'GET', [], null, null, null, [], [
            ['source' => 'status', 'op' => 'eq', 'expected' => 200],
        ]);

        $text = (new TextRenderer())->render((new Runner($factory))->run($scenario));

        self::assertStringContainsString('✗ s1', $text);
        self::assertStringContainsString('Summary: 0 passed, 1 failed, 0 skipped', $text);
    }

    public function testRenderersHandleBatchReport(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{}', 200));
        $factory->queue($this->jsonResponse('{}', 500));

        $scenario = $this->scenario();
        $scenario->steps[] = new HttpStep('s1', '步骤一', 'http://api.example.com/x', 'GET', [], null, null, null, [], [
            ['source' => 'status', 'op' => 'eq', 'expected' => 200],
        ]);

        $runner = new Runner($factory);
        $batch = new BatchReport('users');
        $batch->add(new RecordResult($runner->run($scenario)));
        $batch->add(new RecordResult($runner->run($scenario)));
        $batch->finalize(123.0);

        $text = (new TextRenderer())->render($batch);
        self::assertStringContainsString('Batch dataset: users', $text);
        self::assertStringContainsString('✗ #1', $text);
        self::assertStringContainsString('Batch summary: 1/2 passed, 1 failed (1) in 123ms', $text);

        $decoded = json_decode((new JsonRenderer())->render($batch), true);
        self::assertSame('batch', $decoded['kind']);
        self::assertSame([1], $decoded['failedRecords']);
    }
}
