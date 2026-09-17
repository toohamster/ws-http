<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\AutomatedException;
use Ws\Http\Automated\Pause\EnvironmentSource;
use Ws\Http\Automated\Pause\PauseRegistry;
use Ws\Http\Automated\Pause\PollSource;
use Ws\Http\Automated\Pause\StdinSource;
use Ws\Http\Automated\Pause\ValueResult;
use Ws\Http\Automated\Pause\ValueSource;
use Ws\Http\Automated\PauseStep;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Automated\ScenarioParser;
use Ws\Http\Automated\StepResult;
use Ws\Http\Automated\VariableScope;
use Ws\Http\Contract\ExtractionOutcome;
use Ws\Http\Contract\ExtractorInterface;
use Ws\Http\Response;

/**
 * design/22:pause 步骤 + ValueSource 注册表 + 内建三策略。
 */
final class PauseTest extends TestCase
{
    // ---------- PauseRegistry ----------

    public function testRegistryRegisterGetHas(): void
    {
        $registry = new PauseRegistry([new StdinSource(), new EnvironmentSource()]);

        self::assertTrue($registry->has('stdin'));
        self::assertTrue($registry->has('environment'));
        self::assertFalse($registry->has('poll:x'));
        self::assertInstanceOf(StdinSource::class, $registry->get('stdin'));
    }

    public function testRegistryDuplicateThrows406(): void
    {
        $registry = new PauseRegistry([new StdinSource()]);

        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(406);
        $registry->register(new StdinSource());
    }

    public function testRegistryUnknownThrows407(): void
    {
        $registry = new PauseRegistry();

        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(407);
        $registry->get('nope');
    }

    public function testRegistryUnknownPollThrows407(): void
    {
        $registry = new PauseRegistry();

        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(407);
        $registry->get('poll:email-otp');
    }

    public function testRegistryResolvesPollPrefix(): void
    {
        $registry = new PauseRegistry([], ['email-otp' => new FakeExtractor(ValueResult::got('123456'))]);

        $source = $registry->get('poll:email-otp');
        self::assertInstanceOf(PollSource::class, $source);
    }

    // ---------- StdinSource ----------

    public function testStdinReadsStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "  123456  \n");
        rewind($stream);

        $result = (new StdinSource($stream))->fetch([]);

        self::assertSame(ValueResult::GOT, $result->status());
        self::assertSame('123456', $result->value());
        fclose($stream);
    }

    public function testStdinEofReturnsMissing(): void
    {
        $stream = fopen('php://memory', 'r'); // 空流,读即 EOF
        fclose($stream); // 已关闭流 fgets 返回 false(模拟无输入)

        $result = (new StdinSource($stream))->fetch([]);

        self::assertSame(ValueResult::MISSING, $result->status());
        self::assertStringContainsString('EOF', (string) $result->reason());
    }

    // ---------- EnvironmentSource ----------

    public function testEnvironmentFromEnv(): void
    {
        putenv('WS_TEST_OTP=987654');

        $result = (new EnvironmentSource())->fetch(['env' => 'WS_TEST_OTP']);

        self::assertSame(ValueResult::GOT, $result->status());
        self::assertSame('987654', $result->value());
        putenv('WS_TEST_OTP');
    }

    public function testEnvironmentMissingEnv(): void
    {
        putenv('WS_TEST_OTP_ABSENT');

        $result = (new EnvironmentSource())->fetch(['env' => 'WS_TEST_OTP_ABSENT']);

        self::assertSame(ValueResult::MISSING, $result->status());
        putenv('WS_TEST_OTP_ABSENT');
    }

    public function testEnvironmentFromFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wsp');
        file_put_contents($file, "  555000\nsecond-line\n");

        $result = (new EnvironmentSource())->fetch(['file' => $file]);

        self::assertSame(ValueResult::GOT, $result->status());
        self::assertSame('555000', $result->value());
        unlink($file);
    }

    public function testEnvironmentNoConfigIsMissing(): void
    {
        $result = (new EnvironmentSource())->fetch([]);

        self::assertSame(ValueResult::MISSING, $result->status());
    }

    // ---------- PollSource(时钟/sleep 注入,零延迟) ----------

    public function testPollImmediateValue(): void
    {
        $source = new PollSource(new FakeExtractor(ValueResult::got('111222')));
        $result = $source->fetch([]);

        self::assertSame(ValueResult::GOT, $result->status());
        self::assertSame('111222', $result->value());
    }

    public function testPollRetriesUntilFound(): void
    {
        $extractor = new FakeExtractor(ValueResult::missing('not yet'), ValueResult::got('333444'));
        $sleeps = [];
        $clockValue = 0.0;
        $source = new PollSource(
            $extractor,
            ['interval' => 5.0, 'timeout' => 300.0],
            function (float $s) use (&$sleeps, &$clockValue): void {
                $sleeps[] = $s;
                $clockValue += $s;
            },
            function () use (&$clockValue): float {
                return $clockValue;
            }
        );

        $result = $source->fetch([]);

        self::assertSame(ValueResult::GOT, $result->status());
        self::assertSame('333444', $result->value());
        self::assertSame([5.0], $sleeps);
        self::assertSame(2, $extractor->calls);
    }

    public function testPollTimeoutReturnsMissing(): void
    {
        $extractor = new FakeExtractor(ValueResult::missing('never'));
        $clockValue = 0.0;
        $source = new PollSource(
            $extractor,
            ['interval' => 5.0, 'timeout' => 12.0],
            function (float $s) use (&$clockValue): void {
                $clockValue += $s; // sleep 推进时钟
            },
            function () use (&$clockValue): float {
                return $clockValue;
            }
        );

        $result = $source->fetch([]);

        self::assertSame(ValueResult::MISSING, $result->status());
        self::assertStringContainsString('timed out', (string) $result->reason());
        self::assertSame(3, $extractor->calls); // 0s/5s/10s 三次尝试,10+5 > 12 终止
    }

    // ---------- Runner 集成 ----------

    private function scenario(): Scenario
    {
        $scenario = new Scenario();
        $scenario->id = 'pause-scenario';
        $scenario->name = 'pause 测试场景';

        return $scenario;
    }

    public function testRunnerPauseWritesVariableAndContinues(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "654321\n");
        rewind($stream);

        $runner = new Runner(new \Ws\Http\Tests\Engine\FakeRequestFactory(), null, null, null, new PauseRegistry([
            new StdinSource($stream),
        ]));
        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $runner = new Runner($factory, null, null, null, new PauseRegistry([
            new StdinSource($stream),
        ]));

        $scenario = $this->scenario();
        $scenario->steps[] = new PauseStep('get-otp', '等待验证码', 'otpCode', 'stdin', ['message' => '输入验证码']);
        $scenario->steps[] = new \Ws\Http\Automated\HttpStep(
            'use-otp', '使用验证码', 'http://api.example.com/verify', 'POST',
            [], null, null, ['mode' => 'json', 'content' => '{"code":"${otpCode}"}']
        );

        $report = $runner->run($scenario);

        $steps = $report->steps();
        self::assertSame(StepResult::STATUS_PASSED, $steps[0]->status);
        self::assertSame('pause', $steps[0]->stepType);
        self::assertSame('654321', $steps[0]->extractedVariables['otpCode']);
        self::assertSame(StepResult::STATUS_PASSED, $steps[1]->status);
        // ${otpCode} 回填:后续步骤实际发出的 body 携带该值
        self::assertStringContainsString('654321', (string) $factory->lastSent()['body']->content);
        fclose($stream);
    }

    public function testRunnerPauseFailedTriggersFailFast(): void
    {
        $runner = new Runner(new \Ws\Http\Tests\Engine\FakeRequestFactory(), null, null, null, new PauseRegistry([
            new EnvironmentSource(),
        ]));

        $scenario = $this->scenario();
        $scenario->steps[] = new PauseStep('get-otp', '等待验证码', 'otpCode', 'environment', ['env' => 'WS_MISSING_VAR_XYZ']);
        $scenario->steps[] = new \Ws\Http\Automated\HttpStep('next', '下一步', 'http://api.example.com/x', 'GET');

        $report = $runner->run($scenario);

        $steps = $report->steps();
        self::assertSame(StepResult::STATUS_FAILED, $steps[0]->status);
        self::assertStringContainsString('not set', (string) $steps[0]->failReason);
        self::assertSame(StepResult::STATUS_SKIPPED, $steps[1]->status);
    }

    public function testRunnerPauseWithoutRegistryFails(): void
    {
        $runner = new Runner(new \Ws\Http\Tests\Engine\FakeRequestFactory());

        $scenario = $this->scenario();
        $scenario->steps[] = new PauseStep('p', '暂停', 'v', 'stdin');

        $report = $runner->run($scenario);

        self::assertSame(StepResult::STATUS_FAILED, $report->steps()[0]->status);
        self::assertStringContainsString('PauseRegistry', (string) $report->steps()[0]->failReason);
    }

    public function testRunnerPauseWithExtractRule(): void
    {
        // poll extractor 返回结构化数据 → extract.path 二次提取
        $custom = new class implements ValueSource {
            public static function id(): string
            {
                return 'fake-data';
            }

            public function fetch(array $options): ValueResult
            {
                return ValueResult::got(['data' => ['code' => '777888']]);
            }
        };

        $runner = new Runner(new \Ws\Http\Tests\Engine\FakeRequestFactory(), null, null, null, new PauseRegistry([$custom]));

        $scenario = $this->scenario();
        $scenario->steps[] = new PauseStep('p', '暂停', 'otpCode', 'fake-data', [], '', [
            ['var' => 'otpCode', 'source' => 'json', 'path' => '$.data.code'],
        ]);

        $report = $runner->run($scenario);

        $step = $report->steps()[0];
        self::assertSame(StepResult::STATUS_PASSED, $step->status);
        self::assertSame('777888', $step->extractedVariables['otpCode']);
    }

    // ---------- Schema 校验 V11–V13 ----------

    public function testParserPauseStepValid(): void
    {
        $scenario = (new ScenarioParser())->parseArray([
            'id' => 'p', 'name' => 'n',
            'steps' => [
                ['type' => 'pause', 'id' => 'p1', 'name' => '等码', 'var' => 'otpCode', 'from' => 'stdin', 'options' => ['message' => 'hi']],
            ],
        ]);

        self::assertInstanceOf(PauseStep::class, $scenario->steps[0]);
        self::assertSame('otpCode', $scenario->steps[0]->var());
    }

    public function testParserPauseMissingVarThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'p', 'name' => 'n',
            'steps' => [['type' => 'pause', 'id' => 'p1', 'name' => 'n', 'from' => 'stdin']],
        ]);
    }

    public function testParserPauseMissingFromThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'p', 'name' => 'n',
            'steps' => [['type' => 'pause', 'id' => 'p1', 'name' => 'n', 'var' => 'v']],
        ]);
    }

    public function testParserPauseBadOptionsThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'p', 'name' => 'n',
            'steps' => [
                ['type' => 'pause', 'id' => 'p1', 'name' => 'n', 'var' => 'v', 'from' => 'stdin', 'options' => 'not-an-object'],
            ],
        ]);
    }

    public function testParserPauseUnknownFieldThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'p', 'name' => 'n',
            'steps' => [
                ['type' => 'pause', 'id' => 'p1', 'name' => 'n', 'var' => 'v', 'from' => 'stdin', 'nope' => 1],
            ],
        ]);
    }
}

/**
 * 提取器替身:按调用序号出队 ExtractionOutcome。
 */
final class FakeExtractor implements ExtractorInterface
{
    /** @var array<int, ValueResult> */
    private $results;

    /** @var int */
    public $calls = 0;

    public function __construct(ValueResult ...$results)
    {
        $this->results = $results;
    }

    public function extract(Response $response, string $path): ExtractionOutcome
    {
        $result = $this->results[$this->calls] ?? ValueResult::missing('exhausted');
        $this->calls++;

        if ($result->status() === ValueResult::GOT) {
            return ExtractionOutcome::found($result->value());
        }

        return ExtractionOutcome::missing();
    }
}
