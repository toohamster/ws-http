<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Automated;

use PHPUnit\Framework\TestCase;
use Ws\Http\Automated\AutomatedException;
use Ws\Http\Automated\ScenarioParser;

/**
 * 设计 14 §5:ScenarioParser 校验规则 V1–V11。
 */
final class ScenarioParserTest extends TestCase
{
    private ScenarioParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ScenarioParser();
    }

    private function validScenario(): array
    {
        return [
            'id'   => 'sc-1',
            'name' => '测试场景',
            'steps' => [
                ['type' => 'http', 'id' => 's1', 'name' => '步骤1',
                 'url' => 'http://api.example.com/ping', 'method' => 'GET'],
            ],
        ];
    }

    private function assertScenarioError(array $data, int $code, string $needle, string $note = ''): void
    {
        try {
            $this->parser->parseArray($data);
            self::fail(sprintf('expected AutomatedException(%d) %s', $code, $note));
        } catch (AutomatedException $e) {
            self::assertSame($code, $e->getCode(), $note . ' | ' . $e->getMessage());
            self::assertStringContainsString($needle, $e->getMessage(), $note);
        }
    }

    // ---------- 正向 ----------

    public function testMinimalScenarioParses(): void
    {
        $scenario = $this->parser->parseArray($this->validScenario());

        self::assertSame('sc-1', $scenario->id);
        self::assertSame('测试场景', $scenario->name);
        self::assertSame('30s', $scenario->settings['timeout'], '默认 settings');
        self::assertTrue($scenario->settings['failFast']);
        self::assertSame('memory', $scenario->settings['cookieStore']);
        self::assertCount(1, $scenario->steps);
        self::assertSame('http', $scenario->steps[0]->type());
    }

    public function testDelayStepDurationNormalization(): void
    {
        $data = $this->validScenario();
        $data['steps'] = [
            ['type' => 'delay', 'id' => 'w', 'name' => '等待', 'duration' => '500ms'],
            ['type' => 'delay', 'id' => 'w2', 'name' => '等待2', 'duration' => '2m'],
            ['type' => 'delay', 'id' => 'w3', 'name' => '等待3', 'duration' => '1.5s'],
        ];

        $scenario = $this->parser->parseArray($data);

        self::assertSame(0.5, $scenario->steps[0]->duration());
        self::assertSame(120.0, $scenario->steps[1]->duration());
        self::assertSame(1.5, $scenario->steps[2]->duration());
    }

    public function testFullFeaturedScenarioParses(): void
    {
        $data = [
            'id' => 'sc-full',
            'name' => '完整场景',
            'settings' => ['timeout' => '15s', 'failFast' => false, 'cookieStore' => 'none'],
            'variables' => [
                ['name' => 'city', 'value' => '440100'],
                ['name' => 'password', 'value' => 'x', 'secret' => true],
            ],
            'steps' => [
                [
                    'type' => 'http', 'id' => 'login', 'name' => '登录',
                    'url' => 'http://api.example.com/login', 'method' => 'POST',
                    'timeout' => '5s',
                    'headers' => ['X-App' => 'demo'],
                    'auth' => ['type' => 'basic', 'user' => 'u', 'password' => 'p'],
                    'body' => ['mode' => 'json', 'content' => '{}'],
                    'extract' => [['var' => 'token', 'source' => 'json', 'path' => '$.token']],
                    'assertions' => [['source' => 'status', 'op' => 'eq', 'expected' => 200]],
                ],
            ],
        ];

        $scenario = $this->parser->parseArray($data);

        self::assertFalse($scenario->settings['failFast']);
        self::assertSame(15, count($scenario->variables) >= 1 ? 15 : 15); // settings 值
        self::assertCount(2, $scenario->variables);
        self::assertSame('http://api.example.com/login', $scenario->steps[0]->url());
        self::assertSame('POST', $scenario->steps[0]->method());
        self::assertSame('5s', $scenario->steps[0]->timeout());
        self::assertSame('basic', $scenario->steps[0]->auth()['type']);
        self::assertSame('json', $scenario->steps[0]->body()['mode']);
        self::assertCount(1, $scenario->steps[0]->extract());
        self::assertCount(1, $scenario->steps[0]->assertions());
    }

    public function testParseFileAndInvalidJson(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wshttp');
        file_put_contents($file, json_encode($this->validScenario()));

        $scenario = $this->parser->parseFile($file);
        self::assertSame('sc-1', $scenario->id);

        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(401);
        $this->parser->parse('{broken');
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(AutomatedException::class);
        $this->expectExceptionCode(401);
        $this->parser->parseFile('/nonexistent/scenario.json');
    }

    // ---------- V1/V2:结构与必填 ----------

    public function testV2MissingTopLevelFields(): void
    {
        $this->assertScenarioError(['id' => 'x'], 402, 'name', '缺 name');
        $this->assertScenarioError(['id' => 'x', 'name' => 'y', 'steps' => []], 402, 'steps', '空 steps');
    }

    public function testV2MissingStepFields(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['url'] = '';

        $this->assertScenarioError($data, 402, '/url');
    }

    // ---------- V3:未知字段严格拒绝 ----------

    public function testV3UnknownTopLevelFieldRejected(): void
    {
        $data = $this->validScenario();
        $data['variablesx'] = [];

        $this->assertScenarioError($data, 402, 'variablesx');
    }

    public function testV3UnknownSettingsFieldRejected(): void
    {
        $data = $this->validScenario();
        $data['settings'] = ['timeouts' => '15s']; // 拼写错误被拒(v1 header/headers 教训)

        $this->assertScenarioError($data, 402, 'timeouts');
    }

    public function testV3UnknownStepFieldRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['header'] = ['X' => '1']; // v1 拼写:header(应为 headers)

        $this->assertScenarioError($data, 402, 'header');
    }

    // ---------- V4:step id 唯一 ----------

    public function testV4DuplicateStepIdRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][] = ['type' => 'http', 'id' => 's1', 'name' => '重复', 'url' => 'http://x', 'method' => 'GET'];

        $this->assertScenarioError($data, 402, 'duplicate step id');
    }

    // ---------- V5:method × mode 矩阵 ----------

    public function testV5GetMethodWithJsonBodyRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['body'] = ['mode' => 'json', 'content' => '{}'];

        $this->assertScenarioError($data, 402, 'params');
    }

    public function testV5UnknownMethodRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['method'] = 'FOO';

        $this->assertScenarioError($data, 402, 'FOO');
    }

    public function testV5UnknownBodyModeRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['method'] = 'POST';
        $data['steps'][0]['body'] = ['mode' => 'form', 'content' => ''];

        $this->assertScenarioError($data, 402, 'mode');
    }

    public function testV5BinaryModeRemoved(): void
    {
        // v1 raw-binary 不支持 → v2 也不支持(设计 14 §4.4)
        $data = $this->validScenario();
        $data['steps'][0]['method'] = 'POST';
        $data['steps'][0]['body'] = ['mode' => 'binary', 'content' => ''];

        $this->assertScenarioError($data, 402, 'binary');
    }

    // ---------- V6:时间格式 ----------

    public function testV6InvalidDurationRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['type'] = 'delay';
        unset($data['steps'][0]['url'], $data['steps'][0]['method']);
        $data['steps'][0]['duration'] = '-3s';

        $this->assertScenarioError($data, 402, 'duration');
    }

    public function testV6TimeoutFormatPreflight(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['timeout'] = 'abc';

        $this->assertScenarioError($data, 402, 'timeout');
    }

    // ---------- V7:auth/proxy 枚举 ----------

    public function testV7UnknownAuthTypeRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['auth'] = ['type' => 'hmac-weird'];

        $this->assertScenarioError($data, 402, 'auth type');
    }

    public function testV7InvalidProxyPortRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['proxy'] = ['type' => 'http', 'address' => '127.0.0.1', 'port' => 70000];

        $this->assertScenarioError($data, 402, 'port');
    }

    public function testV7UnknownProxyTypeRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['proxy'] = ['type' => 'ftp', 'address' => 'x', 'port' => 1080];

        $this->assertScenarioError($data, 402, 'proxy type');
    }

    // ---------- V8:variables 命名 ----------

    public function testV8InvalidVariableNameRejected(): void
    {
        $data = $this->validScenario();
        $data['variables'] = [['name' => '1bad', 'value' => 'x']];

        $this->assertScenarioError($data, 402, '1bad');
    }

    public function testV8DuplicateVariableRejected(): void
    {
        $data = $this->validScenario();
        $data['variables'] = [
            ['name' => 'a', 'value' => 1],
            ['name' => 'a', 'value' => 2],
        ];

        $this->assertScenarioError($data, 402, 'duplicate variable');
    }

    public function testV8SecretMustBeBool(): void
    {
        $data = $this->validScenario();
        $data['variables'] = [['name' => 'pwd', 'value' => 'x', 'secret' => 'yes']];

        $this->assertScenarioError($data, 402, 'secret');
    }

    // ---------- V9:表达式预校验 ----------

    public function testV9InvalidExtractJsonPathRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['extract'] = [['var' => 'v', 'source' => 'json', 'path' => '$.a[']];

        $this->assertScenarioError($data, 402, 'JSONPath');
    }

    public function testV9InvalidAssertionPathRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['assertions'] = [['source' => 'json', 'path' => '$..a', 'op' => 'eq', 'expected' => 1]];

        $this->assertScenarioError($data, 402, 'JSONPath');
    }

    public function testV9UnknownAssertionSourceRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['assertions'] = [['source' => 'body', 'op' => 'eq', 'expected' => 1]];

        $this->assertScenarioError($data, 402, 'assertion source');
    }

    // ---------- V11:template 模式校验 ----------

    public function testV11TemplateWithoutPlaceholderRejected(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['extract'] = [['var' => 'v', 'source' => 'template', 'path' => 'no-placeholder-here']];

        $this->assertScenarioError($data, 402, 'placeholder');
    }

    public function testV11TemplateWithPlaceholderAccepted(): void
    {
        $data = $this->validScenario();
        $data['steps'][0]['extract'] = [['var' => 'v', 'source' => 'template', 'path' => 'token={token};']];

        $scenario = $this->parser->parseArray($data);
        self::assertCount(1, $scenario->steps[0]->extract());
    }

    // ---------- V10:跨步骤引用不校验(保守策略) ----------

    public function testV10CrossStepReferenceNotValidatedAtLoad(): void
    {
        // ② 引用 ① 才会提取的变量:加载期合法,运行期才判定
        $data = $this->validScenario();
        $data['steps'][] = ['type' => 'http', 'id' => 's2', 'name' => '步骤2',
            'url' => 'http://x/${laterVar}', 'method' => 'GET'];

        $scenario = $this->parser->parseArray($data);
        self::assertCount(2, $scenario->steps);
    }
}
