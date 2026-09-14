<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\ScenarioResolver;
use Ws\Http\Automated\VariableScope;

/**
 * 设计 15 §4:${var} 深替换(类型保留 / 局部嵌入 / 未定义策略)。
 */
final class ScenarioResolverTest extends TestCase
{
    private ScenarioResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ScenarioResolver();
    }

    private function scope(array $vars = []): VariableScope
    {
        $prepared = [];
        foreach ($vars as $name => $value) {
            $prepared[] = ['name' => $name, 'value' => $value];
        }

        return new VariableScope($prepared);
    }

    public function testSimpleReplacement(): void
    {
        $resolved = $this->resolver->resolve(
            ['url' => 'http://api.example.com/user/${userId}', 'headers' => ['X-Token' => '${token}']],
            $this->scope(['userId' => 'U1', 'token' => 'T1'])
        );

        self::assertSame('http://api.example.com/user/U1', $resolved['url']);
        self::assertSame('T1', $resolved['headers']['X-Token']);
    }

    public function testScalarTypePreservedOnWholeStringMatch(): void
    {
        // 整串完全匹配且变量值为标量 → 类型保留(int/float/bool 不字符串化)
        $resolved = $this->resolver->resolve(
            ['count' => '${count}', 'price' => '${price}', 'flag' => '${flag}'],
            $this->scope(['count' => 3, 'price' => 1.5, 'flag' => true])
        );

        self::assertSame(3, $resolved['count']);
        self::assertSame(1.5, $resolved['price']);
        self::assertTrue($resolved['flag']);
    }

    public function testCompositeValueReturnedWholeOnWholeStringMatch(): void
    {
        // 整串完全匹配且变量值为数组 → 直接返回该数组(类型保留)
        $resolved = $this->resolver->resolve(
            ['params' => '${listVar}'],
            $this->scope(['listVar' => ['a' => '1', 'b' => '2']])
        );

        self::assertSame(['a' => '1', 'b' => '2'], $resolved['params']);
    }

    public function testEmbeddedStringification(): void
    {
        // 局部嵌入 → 字符串化拼接
        $resolved = $this->resolver->resolve(
            ['msg' => 'total=${count} items'],
            $this->scope(['count' => 5])
        );

        self::assertSame('total=5 items', $resolved['msg']);
    }

    public function testCompositeEmbeddedAsJson(): void
    {
        // 复合值局部嵌入 → JSON 编码后拼接
        $resolved = $this->resolver->resolve(
            ['payload' => 'data={"a":1,"b":2}'],
            $this->scope(['data' => ['a' => 1, 'b' => 2]])
        );

        self::assertSame('data={"a":1,"b":2}', $resolved['payload']);
    }

    public function testRecursiveArraysAndObjects(): void
    {
        $resolved = $this->resolver->resolve(
            ['body' => ['content' => [['key' => 'city', 'value' => '${city}'], ['key' => 'static', 'value' => 'x']]]],
            $this->scope(['city' => '440100'])
        );

        self::assertSame('440100', $resolved['body']['content'][0]['value']);
        self::assertSame('x', $resolved['body']['content'][1]['value']);
    }

    public function testObjectInputResolved(): void
    {
        $input = new \stdClass();
        $input->url = 'http://x/${id}';

        $resolved = $this->resolver->resolve($input, $this->scope(['id' => '9']));

        self::assertSame('http://x/9', $resolved->url);
    }

    public function testUndefinedVariableThrowsWithNames(): void
    {
        // 未定义变量 → 异常,消息含变量名(不做静默空串替换)
        try {
            $this->resolver->resolve(
                ['url' => 'http://x/${missingVar}'],
                $this->scope([])
            );
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('missingVar', $e->getMessage());
        }
    }

    public function testUndefinedVariableInsideNestedStructure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ghost');
        $this->resolver->resolve(
            ['headers' => ['X-A' => 'ok', 'X-B' => '${ghost}']],
            $this->scope([])
        );
    }

    public function testPlaceholderSyntaxIsOnlyBraceForm(): void
    {
        // $var 裸形态不是占位符(统一为 ${var},design/14)
        $resolved = $this->resolver->resolve(
            ['v' => '$price'],
            $this->scope(['price' => '9'])
        );

        self::assertSame('$price', $resolved['v']);
    }

    public function testNoPlaceholderPassesThrough(): void
    {
        $input = ['url' => 'http://plain.example.com/', 'n' => 42];

        $resolved = $this->resolver->resolve($input, $this->scope());

        self::assertSame($input, $resolved);
    }

    public function testResolvedValueReferencesLaterVariable(): void
    {
        // 前序提取写入的变量对后续步骤可见(生命周期 ④)
        $scope = $this->scope(['token' => 'T1']);
        $scope->set('ktvId', 'K9'); // 模拟 extract 回写

        $resolved = $this->resolver->resolve(
            ['url' => 'http://x/booking/${ktvId}'],
            $scope
        );

        self::assertSame('http://x/booking/K9', $resolved['url']);
    }
}
