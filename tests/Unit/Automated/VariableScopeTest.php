<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\VariableScope;
use Ws\Http\Automated\VarValue;

/**
 * 设计 15 §2:VariableScope(命名规则 / undefined≠null≠空串 / snapshot)。
 */
final class VariableScopeTest extends TestCase
{
    public function testInitialStateFromVariables(): void
    {
        $scope = new VariableScope([
            ['name' => 'city', 'value' => '440100'],
            ['name' => 'count', 'value' => 3],
        ]);

        self::assertTrue($scope->get('city')->defined);
        self::assertSame('440100', $scope->get('city')->value);
        self::assertSame(3, $scope->get('count')->value);
        self::assertSame(['city', 'count'], $scope->names());
    }

    public function testUndefinedVsNullVsEmptyString(): void
    {
        $scope = new VariableScope([
            ['name' => 'empty_var', 'value' => ''],
            ['name' => 'null_var', 'value' => null],
        ]);

        // 三态区分(修复 v1 empty() 混淆)
        self::assertFalse($scope->get('missing')->defined);
        self::assertTrue($scope->get('empty_var')->defined);
        self::assertSame('', $scope->get('empty_var')->value);
        self::assertTrue($scope->get('null_var')->defined);
        self::assertNull($scope->get('null_var')->value);
    }

    public function testSetUpdatesExistingAndAppendsNew(): void
    {
        $scope = new VariableScope([['name' => 'a', 'value' => 1]]);

        $scope->set('a', 2);
        self::assertSame(2, $scope->get('a')->value);

        $scope->set('b', 'new'); // 提取器回写场景:未声明也可追加
        self::assertTrue($scope->has('b'));
    }

    public function testInvalidNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VariableScope([['name' => '1bad', 'value' => 'x']]);
    }

    public function testInvalidSetNameThrows(): void
    {
        $scope = new VariableScope();

        $this->expectException(\InvalidArgumentException::class);
        $scope->set('bad-name', 'x');
    }

    public function testDuplicateDeclaredNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VariableScope([
            ['name' => 'dup', 'value' => 1],
            ['name' => 'dup', 'value' => 2],
        ]);
    }

    public function testSnapshotReturnsPlainMap(): void
    {
        $scope = new VariableScope([
            ['name' => 'a', 'value' => 1],
            ['name' => 'b', 'value' => ['x' => 'y']],
        ]);
        $scope->set('c', true);

        self::assertSame(['a' => 1, 'b' => ['x' => 'y'], 'c' => true], $scope->snapshot());
    }

    public function testVarValueUndefinedHelper(): void
    {
        self::assertFalse(VarValue::undefined()->defined);
        self::assertNull(VarValue::undefined()->value);
    }

    // ---------- secret 标记(design/15 §2.1.1,随 S9 Report 消费) ----------

    public function testSecretDeclaredInConstructor(): void
    {
        $scope = new VariableScope([
            ['name' => 'username', 'value' => 'testuser'],
            ['name' => 'password', 'value' => 'secret', 'secret' => true],
        ]);

        self::assertTrue($scope->isSecret('password'));
        self::assertFalse($scope->isSecret('username'));
        self::assertSame(['password'], $scope->secrets());
    }

    public function testSecretSurvivesValueOverwrite(): void
    {
        // extract 覆盖 secret 变量(重新登录换 token)时标记保持:按变量名绑定
        $scope = new VariableScope([['name' => 'token', 'value' => 'old', 'secret' => true]]);
        $scope->set('token', 'new-token');

        self::assertTrue($scope->isSecret('token'));
        self::assertSame('new-token', $scope->get('token')->value);
    }

    public function testMarkSecretAtRuntime(): void
    {
        $scope = new VariableScope();
        $scope->set('extractedToken', 'abc');
        $scope->markSecret('extractedToken');

        self::assertTrue($scope->isSecret('extractedToken'));
    }
}
