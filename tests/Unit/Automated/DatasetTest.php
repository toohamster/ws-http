<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\AutomatedException;
use Ws\Http\Automated\Dataset\DatasetRegistry;
use Ws\Http\Automated\Dataset\DatasetRunner;
use Ws\Http\Automated\Dataset\DatasetValidator;
use Ws\Http\Automated\Dataset\InlineLoader;
use Ws\Http\Automated\Dataset\JsonFileLoader;
use Ws\Http\Automated\HttpStep;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Automated\ScenarioParser;
use Ws\Http\Automated\VariableScope;
use Ws\Http\RequestOptions;
use Ws\Http\Response;
use Ws\Http\Tests\Engine\FakeRequestFactory;

/**
 * design/23:数据驱动测试(DatasetRunner / Loader / Validator / BatchReport / Schema V14-V15)。
 */
final class DatasetTest extends TestCase
{
    // ---------- Loader ----------

    public function testInlineLoaderPassthrough(): void
    {
        $records = [['a' => 1], ['a' => 2]];

        self::assertSame($records, (new InlineLoader())->load($records));
    }

    public function testJsonFileLoaderRelativeAndTrim(): void
    {
        $dir = sys_get_temp_dir() . '/ws-dataset-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/users.json', '[{"username":"u1"},{"username":"u2"}]');

        $records = (new JsonFileLoader($dir))->load(['file' => 'users.json']);

        self::assertSame([['username' => 'u1'], ['username' => 'u2']], $records);
        unlink($dir . '/users.json');
        rmdir($dir);
    }

    public function testJsonFileLoaderMissingFileThrows422(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(422);
        (new JsonFileLoader('/nonexistent'))->load(['file' => 'nope.json']);
    }

    public function testJsonFileLoaderNonArrayThrows422(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wsd');
        file_put_contents($file, '{"not":"an array"}');

        try {
            $this->expectException(AutomatedException::class);
            $this->expectExceptionCode(422);
            (new JsonFileLoader('.'))->load(['file' => $file]);
        } finally {
            unlink($file);
        }
    }

    public function testRegistryDuplicateThrows420(): void
    {
        $registry = new DatasetRegistry();
        $registry->register('inline', new InlineLoader());

        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(420);
        $registry->register('inline', new InlineLoader());
    }

    public function testRegistryUnknownThrows421(): void
    {
        $registry = new DatasetRegistry();

        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(421);
        $registry->load('nope', []);
    }

    // ---------- Validator(V16) ----------

    public function testValidatorRejectsEmpty(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(422);
        DatasetValidator::validate([]);
    }

    public function testValidatorRejectsListRecord(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(422);
        DatasetValidator::validate([['a', 'b']]);
    }

    public function testValidatorRejectsHeterogeneous(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(422);
        DatasetValidator::validate([['a' => 1, 'b' => 2], ['a' => 3, 'c' => 4]]);
    }

    public function testValidatorAcceptsHomogeneous(): void
    {
        $records = [['a' => 1, 'b' => 2], ['b' => 4, 'a' => 3]]; // 键序无关

        self::assertSame($records, DatasetValidator::validate($records));
    }

    // ---------- DatasetRunner ----------

    private function singleLoginScenario(): Scenario
    {
        $scenario = new Scenario();
        $scenario->id = 'login-sc';
        $scenario->name = '登录';

        return $scenario;
    }

    public function testEachRecordRunsFullScenarioWithOwnVariables(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{"ok":true,"user":"u1"}'));
        $factory->queue($this->jsonResponse('{"ok":true,"user":"u2"}'));

        $scenario = $this->singleLoginScenario();
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST',
            [], null, null, ['mode' => 'json', 'content' => '{"user":"${username}"}'],
            [['var' => 'who', 'source' => 'json', 'path' => '$.user']],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]]
        );

        $batch = (new DatasetRunner(new Runner($factory)))->run(
            $scenario,
            [['username' => 'u1'], ['username' => 'u2']]
        );

        self::assertTrue($batch->isSuccess());
        self::assertSame(2, $batch->total());
        self::assertSame(2, $batch->passed());
        self::assertSame([], $batch->failedRecordIndexes());
        // 每轮发出的 body 使用各自记录的变量
        self::assertSame('{"user":"u1"}', (string) $factory->sent[0]['body']->content);
        self::assertSame('{"user":"u2"}', (string) $factory->sent[1]['body']->content);
    }

    public function testVariablesDoNotLeakAcrossIterations(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{"user":"u1"}'));
        // 第 2 轮响应没有 token 字段 → 若第 1 轮 token 泄漏,断言仍可能过;用 missing 检查
        $factory->queue($this->jsonResponse('{"user":"u2"}'));

        $scenario = $this->singleLoginScenario();
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST',
            [], null, null, null,
            [['var' => 'token', 'source' => 'json', 'path' => '$.token', 'onMissing' => 'default', 'defaultValue' => 'LEAKED']]
        );

        $batch = (new DatasetRunner(new Runner($factory)))->run(
            $scenario,
            [['username' => 'u1'], ['username' => 'u2']]
        );

        self::assertTrue($batch->isSuccess());
        // 第 2 轮终态变量里 token 应是 default('LEAKED'),而不是第 1 轮的值
        $second = $batch->records()[1]->report->variables();
        self::assertSame('LEAKED', $second['token'] ?? null);
    }

    public function testFailedRecordDoesNotAbortBatch(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{"ok":true}', 200));
        $factory->queue($this->jsonResponse('{"error":"denied"}', 403)); // 第 2 条失败

        $scenario = $this->singleLoginScenario();
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST',
            [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]]
        );

        $batch = (new DatasetRunner(new Runner($factory)))->run(
            $scenario,
            [['username' => 'u1'], ['username' => 'u2']]
        );

        self::assertFalse($batch->isSuccess());
        self::assertSame(2, $batch->total(), '失败不中断批次');
        self::assertSame(1, $batch->passed());
        self::assertSame(1, $batch->failed());
        self::assertSame([1], $batch->failedRecordIndexes());
        self::assertSame('login', $batch->toArray()['failures'][0]['failedStep']);
    }

    public function testDeclaredVariablesOverrideRecordFields(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{}'));
        $factory->queue($this->jsonResponse('{}'));

        $scenario = $this->singleLoginScenario();
        $scenario->variables = [['name' => 'username', 'value' => 'DECLARED']];
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST',
            [], null, null, ['mode' => 'json', 'content' => '{"user":"${username}"}']
        );

        (new DatasetRunner(new Runner($factory)))->run(
            $scenario,
            [['username' => 'u1'], ['username' => 'u2']]
        );

        // 显式声明的 variables 优先于记录字段
        self::assertSame('{"user":"DECLARED"}', (string) $factory->sent[0]['body']->content);
        self::assertSame('{"user":"DECLARED"}', (string) $factory->sent[1]['body']->content);
    }

    public function testRecordVarInjection(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{}'));
        $factory->queue($this->jsonResponse('{}'));

        $scenario = $this->singleLoginScenario();
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST',
            [], null, null, ['mode' => 'json', 'content' => '{"i":${_recordIndex}}']
        );

        (new DatasetRunner(new Runner($factory), ))->run(
            $scenario,
            [['username' => 'u1'], ['username' => 'u2']],
            true
        );

        self::assertSame('{"i":0}', (string) $factory->sent[0]['body']->content);
        self::assertSame('{"i":1}', (string) $factory->sent[1]['body']->content);
    }

    public function testBatchReportShape(): void
    {
        $factory = new FakeRequestFactory();
        $factory->queue($this->jsonResponse('{}', 200));
        $factory->queue($this->jsonResponse('{}', 500));

        $scenario = $this->singleLoginScenario();
        $scenario->steps[] = new HttpStep(
            'login', '登录', 'http://api.example.com/login', 'POST', [], null, null, null, [],
            [['source' => 'status', 'op' => 'eq', 'expected' => 200]]
        );

        $batch = (new DatasetRunner(new Runner($factory)))->run($scenario, [['u' => 'a'], ['u' => 'b']]);
        $array = $batch->toArray();

        self::assertSame('batch', $array['kind']);
        self::assertSame(2, $array['total']);
        self::assertSame(1, $array['passed']);
        self::assertSame(1, $array['failed']);
        self::assertSame([1], $array['failedRecords']);
        self::assertCount(1, $array['failures']);
        self::assertSame(['u' => 'b'], $array['failures'][0]['record']);
        self::assertSame(2, \count($array['records']));
    }

    public function testSingleRecordWithoutIterateMatchesPlainRunner(): void
    {
        // 隔离性:DatasetRunner 单条记录运行 = 直接 Runner 运行
        $makeScenario = function (): Scenario {
            $scenario = $this->singleLoginScenario();
            $scenario->steps[] = new HttpStep(
                'login', '登录', 'http://api.example.com/login', 'POST', [], null, null, null, [],
                [['source' => 'status', 'op' => 'eq', 'expected' => 200]]
            );

            return $scenario;
        };

        $factoryA = new FakeRequestFactory();
        $factoryA->queue($this->jsonResponse('{}', 200));
        $direct = (new Runner($factoryA))->run($makeScenario());

        $factoryB = new FakeRequestFactory();
        $factoryB->queue($this->jsonResponse('{}', 200));
        $viaDataset = (new DatasetRunner(new Runner($factoryB)))->run($makeScenario(), [['u' => 'x']]);

        $this->assertSame($direct->toArray()['summary'], $viaDataset->records()[0]->report->toArray()['summary']);
    }

    // ---------- Schema:datasets/iterate(V14/V15) ----------

    public function testParserDatasetsAndIterateValid(): void
    {
        $scenario = (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'settings' => ['iterate' => 'users'],
            'datasets' => ['users' => [['username' => 'u1']]],
            'steps' => [['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET']],
        ]);

        self::assertSame([['username' => 'u1']], $scenario->datasets['users']);
    }

    public function testParserIterateUnknownDatasetThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'settings' => ['iterate' => 'ghost'],
            'steps' => [['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET']],
        ]);
    }

    public function testParserDatasetsListFormThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'datasets' => [['username' => 'u1']], // 数组形态(非名→值对象)
            'steps' => [['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET']],
        ]);
    }

    // ---------- 工具 ----------

    private function jsonResponse(string $rawBody, int $code = 200): Response
    {
        $headers = sprintf("HTTP/1.1 %d X\r\nContent-Type: application/json\r\n\r\n", $code);

        return new Response(
            ['http_code' => $code, 'header_size' => strlen($headers), 'total_time' => 0.1],
            $rawBody,
            $headers
        );
    }
}
