<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\AutomatedException;
use Ws\Http\Automated\RequestFactory;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\ScenarioParser;

/**
 * S10 CLI 进程级测试:exit code 0/1/2、--format=json、--var 覆盖(design/16 §5)。
 *
 * 直接 exec bin/ws-http(真实进程验证);场景用本地不可达 URL 但断言失败/成功可控。
 */
final class CliTest extends TestCase
{
    private const BIN = __DIR__ . '/../../../bin/ws-http';

    private function fixture(string $name): string
    {
        return __DIR__ . '/../../fixtures/scenarios/' . $name;
    }

    public function testHelpExitsZero(): void
    {
        exec('/usr/local/bin/php74 ' . self::BIN . ' help', $out, $code);

        self::assertSame(0, $code);
        self::assertStringContainsString('Usage', implode("\n", $out));
    }

    public function testUnknownCommandExitsTwo(): void
    {
        exec('/usr/local/bin/php74 ' . self::BIN . ' frobnicate 2>&1', $out, $code);

        self::assertSame(2, $code);
    }

    public function testMissingScriptFileExitsTwo(): void
    {
        exec('/usr/local/bin/php74 ' . self::BIN . ' run /nonexistent/scenario.json 2>&1', $out, $code);

        self::assertSame(2, $code);
        self::assertStringContainsString('[parse]', implode("\n", $out));
    }

    public function testRunPassingScenarioExitsZero(): void
    {
        // 本地 fixtures 里 search 步骤指向不可达 host → 不能用真实 run;
        // 用一个必成功的最小场景:httpbin 不可达时该测试 skip 逻辑不适用 ——
        // 改为对 booking-flow 的完整运行不做网络断言,而是断言失败 exit 1。
        // 真正的 exit 0 验证:构造一个只有 delay 步骤的场景(无网络)。
        $tmp = tempnam(sys_get_temp_dir(), 'wshttp') . '.json';
        file_put_contents($tmp, json_encode([
            'id' => 'cli-ok', 'name' => 'CLI成功',
            'steps' => [['type' => 'delay', 'id' => 'w', 'name' => '等待', 'duration' => '0.01s']],
        ]));

        exec('/usr/local/bin/php74 ' . self::BIN . ' run ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);

        self::assertSame(0, $code);
        self::assertStringContainsString('1 passed', implode("\n", $out));
    }

    public function testRunFailingScenarioExitsOne(): void
    {
        // 传输失败场景(不可达 host)→ failed → exit 1
        $tmp = tempnam(sys_get_temp_dir(), 'wshttp') . '.json';
        file_put_contents($tmp, json_encode([
            'id' => 'cli-fail', 'name' => 'CLI失败',
            'steps' => [['type' => 'http', 'id' => 'down', 'name' => '不可达',
                         'url' => 'http://127.0.0.1:1/unreachable', 'method' => 'GET', 'timeout' => '1s']],
        ]));

        exec('/usr/local/bin/php74 ' . self::BIN . ' run ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);

        self::assertSame(1, $code);
        self::assertStringContainsString('1 failed', implode("\n", $out));
    }

    public function testFormatJsonOutputsReportContract(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wshttp') . '.json';
        file_put_contents($tmp, json_encode([
            'id' => 'cli-json', 'name' => 'JSON报告',
            'steps' => [['type' => 'delay', 'id' => 'w', 'name' => '等待', 'duration' => '0.01s']],
        ]));

        exec('/usr/local/bin/php74 ' . self::BIN . ' run ' . escapeshellarg($tmp) . ' --format=json 2>&1', $out, $code);

        self::assertSame(0, $code);
        $report = json_decode(implode("\n", $out), true);

        self::assertIsArray($report);
        self::assertSame('cli-json', $report['scenario']['id']);
        self::assertTrue($report['summary']['success']);
    }

    public function testVarOverrideWinsOverScriptVariables(): void
    {
        // 用一个断言"变量值 = CLI 覆盖值"的场景:借 report-out + variables 快照验证
        $tmp = tempnam(sys_get_temp_dir(), 'wshttp') . '.json';
        $reportPath = tempnam(sys_get_temp_dir(), 'wshttp') . '.report.json';

        file_put_contents($tmp, json_encode([
            'id' => 'cli-var', 'name' => '变量覆盖',
            'variables' => [['name' => 'env', 'value' => 'script-value']],
            'steps' => [['type' => 'delay', 'id' => 'w', 'name' => '等待', 'duration' => '0.01s']],
        ]));

        exec(sprintf(
            '/usr/local/bin/php74 %s run %s "--var=env=cli-value" --format=json --report-out=%s 2>&1',
            self::BIN,
            escapeshellarg($tmp),
            escapeshellarg($reportPath)
        ), $out, $code);

        self::assertSame(0, $code);
        $report = json_decode((string) file_get_contents($reportPath), true);
        self::assertSame('cli-value', $report['variables']['env'], '--var 覆盖脚本内 variables');
    }
}
