<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\AutomatedException;
use Ws\Http\Automated\HttpStep;
use Ws\Http\Automated\Runner;
use Ws\Http\Automated\Scenario;
use Ws\Http\Automated\ScenarioParser;
use Ws\Http\Tests\Engine\FakeRequestFactory;

/**
 * design/14 §4.5 + design/16 §1.1.1:重定向合并(follow/max,V18 校验)。
 */
final class RedirectTest extends TestCase
{
    // ---------- Runner:三级合并 ----------

    public function testDefaultRedirectFollowsWithMax10(): void
    {
        $factory = new FakeRequestFactory();
        $runner = new Runner($factory);
        $scenario = new Scenario();
        $scenario->id = 'x';
        $scenario->name = 'n';
        $scenario->steps[] = new HttpStep('s1', 'S1', 'http://api.example.com/r', 'GET');

        $runner->run($scenario);

        self::assertSame(10, $factory->capturedOptions[0]->maxRedirects());
    }

    public function testStepRedirectFollowFalseDisablesFollowing(): void
    {
        $factory = new FakeRequestFactory();
        $runner = new Runner($factory);
        $scenario = new Scenario();
        $scenario->id = 'x';
        $scenario->name = 'n';
        $scenario->steps[] = new HttpStep('s1', 'S1', 'http://api.example.com/r', 'GET', [], null, null, null, [], [], null, ['follow' => false, 'max' => 0]);

        $runner->run($scenario);

        self::assertSame(0, $factory->capturedOptions[0]->maxRedirects());
    }

    public function testStepRedirectMaxOverrides(): void
    {
        $factory = new FakeRequestFactory();
        $runner = new Runner($factory);
        $scenario = new Scenario();
        $scenario->id = 'x';
        $scenario->name = 'n';
        $scenario->steps[] = new HttpStep('s1', 'S1', 'http://api.example.com/r', 'GET', [], null, null, null, [], [], null, ['follow' => true, 'max' => 3]);

        $runner->run($scenario);

        self::assertSame(3, $factory->capturedOptions[0]->maxRedirects());
    }

    public function testSettingsRedirectAppliesAndStepOverrides(): void
    {
        $factory = new FakeRequestFactory();
        $runner = new Runner($factory);
        $scenario = new Scenario();
        $scenario->id = 'x';
        $scenario->name = 'n';
        $scenario->settings['redirect'] = ['follow' => true, 'max' => 5];
        $scenario->steps[] = new HttpStep('s1', 'S1', 'http://api.example.com/r', 'GET');
        $scenario->steps[] = new HttpStep('s2', 'S2', 'http://api.example.com/r', 'GET', [], null, null, null, [], [], null, ['follow' => true, 'max' => 2]);

        $runner->run($scenario);

        self::assertSame(5, $factory->capturedOptions[0]->maxRedirects(), 'settings.redirect 生效');
        self::assertSame(2, $factory->capturedOptions[1]->maxRedirects(), '步骤覆盖 settings');
    }

    // ---------- Schema:V18 ----------

    public function testParserSettingsRedirectValid(): void
    {
        $scenario = (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'settings' => ['redirect' => ['follow' => false]],
            'steps' => [['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET']],
        ]);

        self::assertSame(['follow' => false, 'max' => 10], $scenario->settings['redirect']);
    }

    public function testParserStepRedirectValid(): void
    {
        $scenario = (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'steps' => [
                ['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET',
                    'redirect' => ['follow' => true, 'max' => 2]],
            ],
        ]);

        self::assertInstanceOf(HttpStep::class, $scenario->steps[0]);
        self::assertSame(['follow' => true, 'max' => 2], $scenario->steps[0]->redirect());
    }

    public function testParserRedirectFollowFalseWithMaxThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'steps' => [
                ['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET',
                    'redirect' => ['follow' => false, 'max' => 3]],
            ],
        ]);
    }

    public function testParserRedirectBadFollowThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'steps' => [
                ['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET',
                    'redirect' => ['follow' => 'yes']],
            ],
        ]);
    }

    public function testParserRedirectNegativeMaxThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'settings' => ['redirect' => ['max' => -1]],
            'steps' => [['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET']],
        ]);
    }

    public function testParserRedirectUnknownFieldThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(402);
        (new ScenarioParser())->parseArray([
            'id' => 'x', 'name' => 'n',
            'steps' => [
                ['type' => 'http', 'id' => 's1', 'name' => 'S1', 'url' => 'http://a.example.com/', 'method' => 'GET',
                    'redirect' => ['mode' => 'aggressive']],
            ],
        ]);
    }
}
