<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\Redactor;

/**
 * design/16 §3.3 + design/15 §2.1.1:敏感值脱敏。
 */
final class RedactorTest extends TestCase
{
    public function testScalarStringRedacted(): void
    {
        self::assertSame('***', Redactor::apply('secret-pass', ['secret-pass']));
    }

    public function testSecretEmbeddedInString(): void
    {
        self::assertSame('Bearer ***', Redactor::apply('Bearer secret-pass', ['secret-pass']));
    }

    public function testNestedArrayAndObjectRedacted(): void
    {
        $output = [
            'headers' => ['Authorization' => 'Bearer secret-pass'],
            'variables' => (object) ['password' => 'secret-pass', 'user' => 'plain'],
        ];

        $redacted = Redactor::apply($output, ['secret-pass']);

        self::assertSame('Bearer ***', $redacted['headers']['Authorization']);
        self::assertSame('***', $redacted['variables']->password);
        self::assertSame('plain', $redacted['variables']->user, '非敏感值不动');
    }

    public function testMultipleSecrets(): void
    {
        self::assertSame('*** / ***', Redactor::apply('a1 / b2', ['a1', 'b2']));
    }

    public function testNoSecretsPassesThrough(): void
    {
        $output = ['a' => 'x'];
        self::assertSame($output, Redactor::apply($output, []));
    }

    public function testNonStringSecretsIgnored(): void
    {
        self::assertSame('int 5', Redactor::apply('int 5', [5]));
    }
}
