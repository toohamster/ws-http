<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Expression;

use PHPUnit\Framework\TestCase;
use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Expression\ExpressionException;

/**
 * 设计 13:表达式引擎 —— 文法与求值全量用例。
 */
final class ExpressionEvaluatorTest extends TestCase
{
    private ExpressionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ExpressionEvaluator();
    }

    /** 便捷求值 */
    private function eval(string $expr, $data)
    {
        return $this->evaluator->evaluate($data, $expr);
    }

    /** 便捷:单值断言 */
    private function assertFirst($expected, string $expr, $data): void
    {
        $result = $this->eval($expr, $data);
        self::assertSame($expected, $result->first(), "expr: {$expr}");
    }

    private function assertEmptySet(string $expr, $data): void
    {
        $result = $this->eval($expr, $data);
        self::assertTrue($result->isEmpty(), "expr: {$expr} should match nothing");
    }

    // ---------- 简单路径(90% 用法):member + index ----------

    public function testSimpleMemberPath(): void
    {
        $data = ['list' => ['xktvid' => 'K1'], 'result' => 0];

        $this->assertFirst('K1', '$.list.xktvid', $data);
        $this->assertFirst('K1', 'list.xktvid', $data); // $ 可省略
        $this->assertFirst(0, '$.result', $data);
    }

    public function testIndexAccess(): void
    {
        $data = ['list' => [['xktvid' => 'K1'], ['xktvid' => 'K2']]];

        $this->assertFirst('K1', '$.list[0].xktvid', $data);
        $this->assertFirst('K2', '$.list[1].xktvid', $data);
    }

    public function testNegativeIndex(): void
    {
        $data = ['list' => ['a', 'b', 'c']];

        $this->assertFirst('c', '$.list[-1]', $data);
        $this->assertFirst('a', '$.list[-3]', $data);
    }

    public function testV1PathsFromExampleJson(): void
    {
        // design/13 §6:v1 example.json 中出现的全部 8 条路径形态
        $data = [
            'token'    => 'T1',
            'openid'   => 'O1',
            'userid'   => 'U1',
            'list'     => [['xktvid' => 'K9'], ['xktvid' => 'K10']],
            'total'    => 2,
            'data'     => [
                'xktvid'     => 'K9',
                'taocaninfo' => [
                    'roomtype' => [['id' => 'R1'], ['id' => 'R2']],
                    'course'   => [['starttime' => ['time' => '18:00'], 'endtime' => ['time' => '21:00']]],
                    'days'     => ['2026-09-12'],
                ],
            ],
        ];

        $this->assertFirst('T1', '$.token', $data);
        $this->assertFirst('O1', '$.openid', $data);
        $this->assertFirst('K9', '$.list[0].xktvid', $data);
        $this->assertFirst('R1', '$.data.taocaninfo.roomtype[0].id', $data);
        $this->assertFirst('18:00', '$.data.taocaninfo.course[0].starttime.time', $data);
        $this->assertFirst('21:00', '$.data.taocaninfo.course[0].endtime.time', $data);
        $this->assertFirst('2026-09-12', '$.data.taocaninfo.days[0]', $data);
        $this->assertFirst('K9', '$.data.xktvid', $data);
    }

    // ---------- 通配 ----------

    public function testWildcardOnArray(): void
    {
        $data = ['roomtype' => [['id' => 'R1'], ['id' => 'R2']]];

        $result = $this->eval('$.roomtype[*].id', $data);
        self::assertSame(['R1', 'R2'], $result->all());
    }

    public function testWildcardOnObject(): void
    {
        $data = ['a' => 1, 'b' => 2];

        $result = $this->eval('$.*', $data);
        self::assertSame([1, 2], $result->all());
    }

    public function testWildcardThenDrill(): void
    {
        $data = ['users' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C']]];

        self::assertSame(['A', 'B', 'C'], $this->eval('$.users[*].name', $data)->all());
    }

    // ---------- 切片 ----------

    public function testSliceBasic(): void
    {
        $data = ['l' => [1, 2, 3, 4, 5]];

        self::assertSame([1, 2], $this->eval('$.l[0:2]', $data)->all(), '[0:2] 前取后舍');
        self::assertSame([2, 3, 4, 5], $this->eval('$.l[1:]', $data)->all());
        self::assertSame([1, 2], $this->eval('$.l[:2]', $data)->all());
        self::assertSame([3, 4, 5], $this->eval('$.l[2:]', $data)->all());
    }

    public function testSliceNegativeAndStep(): void
    {
        $data = ['l' => [1, 2, 3, 4, 5]];

        self::assertSame([4, 5], $this->eval('$.l[-2:]', $data)->all());
        self::assertSame([1, 3, 5], $this->eval('$.l[::2]', $data)->all());
        self::assertSame([5, 4, 3, 2, 1], $this->eval('$.l[::-1]', $data)->all());
    }

    // ---------- 联合 ----------

    public function testUnionIndices(): void
    {
        $data = ['l' => ['a', 'b', 'c', 'd']];

        self::assertSame(['a', 'c'], $this->eval('$.l[0,2]', $data)->all(), '联合保序');
        self::assertSame(['d', 'a'], $this->eval('$.l[3,0]', $data)->all(), '按选择器顺序');
    }

    // ---------- 引号键 ----------

    public function testQuotedKeys(): void
    {
        $data = ['weird-key' => 'V1', '数 据' => 'V2', "q'uote" => 'V3'];

        $this->assertFirst('V1', '$["weird-key"]', $data);
        $this->assertFirst('V2', '$["数 据"]', $data);
        $this->assertFirst('V3', "\$['q\\'uote']", $data);
    }

    // ---------- filter(深水区) ----------

    public function testFilterComparison(): void
    {
        $data = ['items' => [
            ['name' => 'A', 'status' => 'active', 'price' => 100],
            ['name' => 'B', 'status' => 'inactive', 'price' => 50],
            ['name' => 'C', 'status' => 'active', 'price' => 200],
        ]];

        self::assertSame(['A', 'C'], $this->eval('$.items[?(@.status == "active")].name', $data)->all());
        self::assertSame(['B'], $this->eval('$.items[?(@.status != "active")].name', $data)->all());
        self::assertSame(['C'], $this->eval('$.items[?(@.price > 150)].name', $data)->all());
        self::assertSame(['A', 'B'], $this->eval('$.items[?(@.price <= 100)].name', $data)->all());
        self::assertSame(['A', 'B', 'C'], $this->eval('$.items[?(@.price >= 50)].name', $data)->all());
        self::assertSame(['A', 'B'], $this->eval('$.items[?(@.price < 150)].name', $data)->all(), 'B=50 也 < 150');
    }

    public function testFilterNumericStringComparison(): void
    {
        // 价格是数字串("150")时与数值字面量比较应成立
        $data = ['items' => [['price' => '150'], ['price' => '50']]];

        self::assertSame([0], array_keys($this->eval('$.items[?(@.price > 100)]', $data)->all()));
    }

    public function testFilterExistence(): void
    {
        $data = ['items' => [
            ['name' => 'A', 'email' => 'a@x'],
            ['name' => 'B'],
        ]];

        self::assertSame(['A'], $this->eval('$.items[?(@.email)].name', $data)->all(), '@.attr 非空即真');
    }

    public function testFilterRegex(): void
    {
        $data = ['items' => [
            ['id' => 'ktv-001'], ['id' => 'bar-002'], ['id' => 'ktv-003'],
        ]];

        self::assertSame(
            ['ktv-001', 'ktv-003'],
            $this->eval('$.items[?(@.id =~ /^ktv-.*$/)].id', $data)->all()
        );
    }

    public function testFilterBooleanLiteral(): void
    {
        // bool 字面量比较(内容断言,不依赖 all() 重排后的索引)
        $data = ['items' => [
            ['on' => true, 'note' => 'x'],
            ['on' => false, 'note' => null],
        ]];

        $trueMatch = $this->eval('$.items[?(@.on == true)]', $data);
        self::assertSame(1, $trueMatch->count());
        self::assertSame('x', $trueMatch->first()['note']);

        $falseMatch = $this->eval('$.items[?(@.on == false)]', $data);
        self::assertSame(1, $falseMatch->count());
        self::assertNull($falseMatch->first()['note']);
    }

    public function testFilterOnObjectValues(): void
    {
        $data = ['users' => [
            'u1' => ['age' => 30],
            'u2' => ['age' => 17],
        ]];

        self::assertSame([['age' => 30]], $this->eval('$.users[?(@.age > 18)]', $data)->all(), 'filter 作用于对象属性值');
    }

    // ---------- 宽容求值:结构不匹配 → 空集,不抛 ----------

    public function testTolerantEvaluationReturnsEmptySet(): void
    {
        $data = ['a' => ['b' => 'v']];

        $this->assertEmptySet('$.a.missing.deep', $data, '缺失键下钻');
        $this->assertEmptySet('$.a.b.c', $data, '标量上继续下钻');
        $this->assertEmptySet('$.a[0]', $data, '对象上取索引');
        $this->assertEmptySet('$.a.b[9]', ['a' => ['b' => ['x']]], '索引越界');
    }

    public function testEvaluateOnScalarRootReturnsEmptySet(): void
    {
        $this->assertEmptySet('$.anything', 'scalar-root');
        $this->assertEmptySet('$.x', null);
        $this->assertEmptySet('$.x', 42);
    }

    public function testEmptyArrayIndexIsMissingNotError(): void
    {
        $this->assertEmptySet('$.l[0]', ['l' => []]);
    }

    // ---------- EvaluationResult 语义 ----------

    public function testEvaluationResultAccessors(): void
    {
        // 设计语义:member 返回节点本身(整个数组是一个节点);元素级用 [*]
        $node = $this->eval('$.l', ['l' => [1, 2]]);
        self::assertSame(1, $node->count());
        self::assertSame([1, 2], $node->first());

        $elements = $this->eval('$.l[*]', ['l' => [1, 2]]);
        self::assertSame(2, $elements->count());
        self::assertSame([1, 2], $elements->all());
        self::assertSame(1, $elements->first());
        self::assertFalse($elements->isEmpty());

        $empty = $this->eval('$.missing', []);
        self::assertSame(0, $empty->count());
        self::assertNull($empty->first());
        self::assertTrue($empty->isEmpty());
    }

    public function testFirstOnObjectMatch(): void
    {
        $obj = ['id' => 7];
        $result = $this->eval('$.data', ['data' => $obj]);

        self::assertSame($obj, $result->first(), '对象节点整体返回');
    }

    // ---------- 语法错误 ----------

    public function testSyntaxErrorsWithPosition(): void
    {
        $bad = [
            '$.a[',           // 未闭合 [
            '$.[0]',          // 缺 member
            '$.a[]',          // 空 []
            '$.a[*',          // 未闭合通配
            '$.a[?(@.x >)]',  // 残缺 filter
            '$.a[?(@.x ==)]', // filter 缺字面量
            '$..a',           // 递归下降不支持
            '$.a[b',          // 引号未闭合
            '$.a[1:2:3:4]',   // 切片段过多
            'a..b',           // 双点
        ];

        foreach ($bad as $expr) {
            try {
                $this->evaluator->evaluate(['a' => []], $expr);
                self::fail("expected ExpressionException(201) for: {$expr}");
            } catch (ExpressionException $e) {
                self::assertSame(201, $e->getCode(), "expr: {$expr}");
                self::assertStringContainsString($expr, $e->getMessage());
            }
        }
    }

    public function testValidateRejectsInvalidAndAcceptsValid(): void
    {
        $this->evaluator->validate('$.a.b[0].c');
        $this->evaluator->validate('a.b');
        $this->evaluator->validate('$.items[?(@.x == "y")]');

        $this->expectException(ExpressionException::class);
        $this->expectExceptionCode(201);
        $this->evaluator->validate('$.a[');
    }

    // ---------- 词法边界 ----------

    public function testNumericKeysOnArrayAsMember(): void
    {
        // 数组上的数字串 member 按索引(设计 13 §3.2)
        $this->assertFirst('b', '$.l.1', ['l' => ['a', 'b']]);
    }

    public function testUnicodeValuesPassThrough(): void
    {
        $this->assertFirst('值', '$.名', ['名' => '值']);
    }

    /**
     * 诊断:filter 中 null/bool 字面量比较的端到端行为。
     * (组件级 looseEquals 单测已通过,此测试锁定端到端语义防回归)
     */
    public function testFilterNullAndBoolLiteralEndToEnd(): void
    {
        $data = ['items' => [
            ['on' => true, 'note' => 'x'],
            ['on' => false, 'note' => null],
        ]];

        // null 字面量:仅 note=null 的元素匹配
        $nullMatch = $this->eval('$.items[?(@.note == null)]', $data);
        self::assertSame(1, $nullMatch->count(), 'null 字面量只匹配 note=null 元素,实际: ' . json_encode($nullMatch->all()));

        // bool 字面量
        $trueMatch = $this->eval('$.items[?(@.on == true)]', $data);
        self::assertSame(1, $trueMatch->count());
        self::assertSame('x', $trueMatch->first()['note']);
    }
}
