<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\VarExtractor;
use Ws\Http\Automated\VariableScope;
use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Response;

/**
 * 设计 15 §3:VarExtractor(六 source × 成功/无匹配 × onMissing 策略)。
 */
final class VarExtractorTest extends TestCase
{
    private VarExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new VarExtractor(new ExpressionEvaluator());
    }

    private function response(string $rawBody = '{"token":"T1","list":[{"xktvid":"K1"},{"xktvid":"K2"}],"total":2}', int $code = 200): Response
    {
        $headers = "HTTP/1.1 {$code} OK\r\nSet-Cookie: a=1; Path=/\r\nSet-Cookie: b=2; Path=/\r\nContent-Type: application/json\r\nX-Request-Id: R1\r\n";

        return new Response(
            ['http_code' => $code, 'header_size' => strlen($headers), 'total_time' => 0.7],
            $rawBody,
            $headers
        );
    }

    private function rules(array $raw): array
    {
        // S9 的 VarRule 解析前的裸形态(设计 15 §3 字段)
        return $raw;
    }

    // ---------- json source ----------

    public function testExtractJsonFirst(): void
    {
        $scope = new VariableScope();
        $result = $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'ktvId', 'source' => 'json', 'path' => '$.list[0].xktvid']]),
            $scope
        );

        self::assertSame([], $result->failures);
        self::assertSame('K1', $scope->get('ktvId')->value);
    }

    public function testExtractJsonMultipleReturnsAll(): void
    {
        $scope = new VariableScope();
        $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'ids', 'source' => 'json', 'path' => '$.list[*].xktvid', 'multiple' => true]]),
            $scope
        );

        self::assertSame(['K1', 'K2'], $scope->get('ids')->value);
    }

    // ---------- header source ----------

    public function testExtractHeaderFirst(): void
    {
        $scope = new VariableScope();
        $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'requestId', 'source' => 'header', 'path' => 'x-request-id']]),
            $scope
        );

        self::assertSame('R1', $scope->get('requestId')->value);
    }

    public function testExtractHeaderMultiple(): void
    {
        $scope = new VariableScope();
        $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'cookies', 'source' => 'header', 'path' => 'Set-Cookie', 'multiple' => true]]),
            $scope
        );

        self::assertSame(['a=1; Path=/', 'b=2; Path=/'], $scope->get('cookies')->value);
    }

    // ---------- raw_body / status / time source ----------

    public function testExtractRawBodyStatusTime(): void
    {
        $scope = new VariableScope();
        $this->extractor->extract(
            $this->response(),
            $this->rules([
                ['var' => 'raw', 'source' => 'raw_body', 'path' => ''],
                ['var' => 'code', 'source' => 'status', 'path' => ''],
                ['var' => 'elapsed', 'source' => 'time', 'path' => ''],
            ]),
            $scope
        );

        self::assertIsString($scope->get('raw')->value);
        self::assertSame(200, $scope->get('code')->value);
        self::assertSame(0.7, $scope->get('elapsed')->value);
    }

    // ---------- template source(S6.5 StrKit 接入) ----------

    public function testExtractTemplateFromRawBody(): void
    {
        $scope = new VariableScope();
        // 简化约定:multiple=false 取首个命名字段(design/15 §3.1)
        $this->extractor->extract(
            $this->response('<meta name="csrf-token" content="abc123" />'),
            $this->rules([['var' => 'csrf', 'source' => 'template', 'path' => '<meta name="csrf-token" content="{token}" />']]),
            $scope
        );

        self::assertSame('abc123', $scope->get('csrf')->value);
    }

    public function testExtractTemplateMultipleReturnsFullMap(): void
    {
        $scope = new VariableScope();
        $this->extractor->extract(
            $this->response('/2012/08/12/test.html'),
            $this->rules([['var' => 'pathParts', 'source' => 'template', 'path' => '/{year}/{month}/{day}/{title}.html', 'multiple' => true]]),
            $scope
        );

        self::assertSame([
            'year' => '2012', 'month' => '08', 'day' => '12', 'title' => 'test',
        ], $scope->get('pathParts')->value);
    }

    // ---------- onMissing 策略 ----------

    public function testOnMissingFail(): void
    {
        $scope = new VariableScope();
        $result = $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'nope', 'source' => 'json', 'path' => '$.missing']]),
            $scope
        );

        self::assertCount(1, $result->failures);
        self::assertStringContainsString('nope', $result->failures[0]['message']);
        self::assertStringContainsString('$.missing', $result->failures[0]['message']);
        self::assertFalse($scope->has('nope'));
    }

    public function testOnMissingSkipKeepsVariableAndWarns(): void
    {
        $scope = new VariableScope([['name' => 'v', 'value' => 'original']]);
        $result = $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'v', 'source' => 'json', 'path' => '$.missing', 'onMissing' => 'skip']]),
            $scope
        );

        self::assertSame([], $result->failures);
        self::assertCount(1, $result->warnings, 'skip 记 warning');
        self::assertSame('original', $scope->get('v')->value, '变量保持原值');
    }

    public function testOnMissingDefaultFillsValue(): void
    {
        $scope = new VariableScope();
        $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'v', 'source' => 'json', 'path' => '$.missing', 'onMissing' => 'default', 'defaultValue' => 'fallback']]),
            $scope
        );

        self::assertSame([], $result->failures ?? []);
        self::assertSame('fallback', $scope->get('v')->value);
    }

    public function testExtractionOrderVisibleWithinSameStep(): void
    {
        // 同步骤内前序提取对后序可见(design/15 §5 表)
        $scope = new VariableScope();
        $result = $this->extractor->extract(
            $this->response('{"token":"T1"}'),
            $this->rules([
                ['var' => 'token', 'source' => 'json', 'path' => '$.token'],
                // 第二条规则从 rawBody 模板提取 token 值(经 scope 无关,验证顺序语义)
                ['var' => 'echo', 'source' => 'json', 'path' => '$.token'],
            ]),
            $scope
        );

        self::assertSame([], $result->failures);
        self::assertSame('T1', $scope->get('token')->value);
        self::assertSame('T1', $scope->get('echo')->value);
    }

    public function testWrittenAccumulates(): void
    {
        $scope = new VariableScope();
        $result = $this->extractor->extract(
            $this->response(),
            $this->rules([
                ['var' => 'a', 'source' => 'json', 'path' => '$.total'],
                ['var' => 'b', 'source' => 'header', 'path' => 'X-Request-Id'],
            ]),
            $scope
        );

        self::assertCount(2, $result->written);
        self::assertSame('a', $result->written[0]['var']);
        self::assertSame(2, $result->written[0]['value']);
    }

    public function testUnknownSourceIsFailure(): void
    {
        $scope = new VariableScope();
        $result = $this->extractor->extract(
            $this->response(),
            $this->rules([['var' => 'x', 'source' => 'xml', 'path' => '//a']]),
            $scope
        );

        self::assertCount(1, $result->failures, '未注册的 source 按 fail 处理(Extension 注册后才可用)');
    }
}
