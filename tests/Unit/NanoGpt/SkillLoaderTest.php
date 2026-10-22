<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\SkillDefinition;
use Ws\Http\NanoGpt\SkillLoader;

/**
 * design/26:Skill 装载(SkillLoader,与 design/25 §4 同源)+ 定义校验(S1–S4)+ 渲染。
 *
 * 钉住:S1–S4 各拒绝态 613;跨文件重名 613(批量期);目录不存在 = 零 skill 不创建;
 * 渲染参数对位/required 614/default/前缀标记;allowedToolsClause 未知名忽略 + 提示。
 */
final class SkillLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-skill-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function writeSkill(array $spec, string $file = 'a.skill.json'): string
    {
        $path = $this->dir . '/' . $file;
        file_put_contents($path, (string) json_encode($spec, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    private function validSpec(): array
    {
        return [
            'name'        => 'json-report',
            'description' => 'Analyze a JSON file',
            'instruction' => '分析 {path}:先 read_file 读取,输出报告。不要修改任何文件。',
            'arguments'   => [
                'path' => ['type' => 'string', 'description' => 'JSON file path', 'required' => true],
            ],
            'allowedTools' => ['read_file', 'list_dir'],
        ];
    }

    // ---------- 装载 ----------

    public function testLoadAllParsesValidSkill(): void
    {
        $this->writeSkill($this->validSpec());

        $skills = (new SkillLoader($this->dir))->loadAll();

        self::assertCount(1, $skills);
        $skill = $skills['json-report'];
        self::assertSame('json-report', $skill->name());
        self::assertSame('Analyze a JSON file', $skill->description());
        self::assertSame(['read_file', 'list_dir'], $skill->allowedTools());
        self::assertSame('a.skill.json', $skill->sourceFile());
    }

    public function testMissingDirReturnsEmptyWithoutCreating(): void
    {
        $loader = new SkillLoader($this->dir . '/nope');

        self::assertSame([], $loader->loadAll());
        self::assertDirectoryDoesNotExist($this->dir . '/nope'); // P3:不自动创建
    }

    public function testSingleInvalidFileSkippedWithSidecarLog(): void
    {
        $this->writeSkill($this->validSpec(), 'good.skill.json');
        file_put_contents($this->dir . '/bad.skill.json', '{"name":"x"}'); // 缺 instruction
        $this->writeSkill(['name' => 'Y', 'instruction' => 'x'], 'upper.skill.json'); // S1 大写名

        $skills = (new SkillLoader($this->dir))->loadAll();

        self::assertSame(['json-report'], array_keys($skills)); // 非法跳过,其余继续
        self::assertFileExists($this->dir . '/.loader-errors.log');
    }

    public function testDuplicateNameAcrossFilesThrows613(): void
    {
        $this->writeSkill($this->validSpec(), 'a.skill.json');
        $this->writeSkill($this->validSpec(), 'b.skill.json');

        try {
            (new SkillLoader($this->dir))->loadAll();
            self::fail('expected 613 duplicate');
        } catch (AgentException $e) {
            self::assertSame(613, $e->getCode());
            self::assertStringContainsString('a.skill.json', $e->getMessage());
            self::assertStringContainsString('b.skill.json', $e->getMessage()); // S1:提示两个文件名
        }
    }

    // ---------- S1–S4 校验 ----------

    public function testS1RejectsIllegalName(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        new SkillDefinition(['name' => 'Json-Report', 'instruction' => 'x']);
    }

    public function testS2RejectsEmptyInstruction(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        new SkillDefinition(['name' => 'x', 'instruction' => '']);
    }

    public function testS2RejectsPlaceholderWithoutDefinition(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        new SkillDefinition([
            'name'        => 'x',
            'instruction' => '分析 {path} 与 {missing}',
            'arguments'   => ['path' => ['type' => 'string']],
        ]);
    }

    public function testS2UnreferencedArgumentWarnsNotRejects(): void
    {
        $skill = new SkillDefinition([
            'name'        => 'x',
            'instruction' => '分析 {path}',
            'arguments'   => [
                'path'  => ['type' => 'string'],
                'extra' => ['type' => 'string'], // 未被模板引用
            ],
        ]);

        self::assertCount(1, $skill->warnings());
        self::assertStringContainsString('extra', $skill->warnings()[0]);
    }

    public function testS3RejectsBadArgumentType(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        new SkillDefinition([
            'name'        => 'x',
            'instruction' => '用 {n}',
            'arguments'   => ['n' => ['type' => 'integer']], // 只允许 string|number|boolean
        ]);
    }

    public function testS4RejectsNonArrayAllowedTools(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionCode(613);
        new SkillDefinition(['name' => 'x', 'instruction' => 'y', 'allowedTools' => 'read_file']);
    }

    // ---------- 渲染 ----------

    public function testRenderPositionalAndPrefix(): void
    {
        $skill = new SkillDefinition($this->validSpec());

        $rendered = $skill->render(['data.json']);

        self::assertSame('[skill: json-report] 分析 data.json:先 read_file 读取,输出报告。不要修改任何文件。', $rendered);
    }

    public function testRenderNamedArgs(): void
    {
        // 名值对由命令层 parseArgs 解析(key:value / 引号剥除);Definition 层收到的已是名值数组
        $skill = new SkillDefinition($this->validSpec());

        $rendered = $skill->render(['path' => 'my data.json']);

        self::assertStringContainsString('分析 my data.json:', $rendered);
    }

    public function testRenderMissingRequiredThrows614(): void
    {
        $skill = new SkillDefinition($this->validSpec());

        try {
            $skill->render([]);
            self::fail('expected 614');
        } catch (AgentException $e) {
            self::assertSame(614, $e->getCode()); // 执行期参数校验:经命令提示,不发请求
            self::assertStringContainsString('missing required argument "path"', $e->getMessage());
        }
    }

    public function testRenderDefaultFillsOptionalSlot(): void
    {
        $skill = new SkillDefinition([
            'name'        => 'x',
            'instruction' => '处理 {src} 到 {dst}',
            'arguments'   => [
                'src' => ['type' => 'string', 'required' => true],
                'dst' => ['type' => 'string', 'default' => './out.txt'],
            ],
        ]);

        $rendered = $skill->render(['in.txt']);

        self::assertSame('[skill: x] 处理 in.txt 到 ./out.txt', $rendered);
    }

    // ---------- allowedToolsClause(执行期约束) ----------

    public function testAllowedToolsClauseFiltersUnknownAndReports(): void
    {
        $skill = new SkillDefinition($this->validSpec()); // read_file, list_dir

        $clause = $skill->allowedToolsClause(['read_file']); // list_dir 未注册

        self::assertNotNull($clause);
        self::assertStringContainsString('read_file', $clause);
        self::assertStringNotContainsString('list_dir, ', $clause);
        self::assertStringContainsString('ignored unknown tools: list_dir', $clause); // S4 执行期
    }

    public function testAllowedToolsClauseNullWhenUnrestricted(): void
    {
        $skill = new SkillDefinition(['name' => 'x', 'instruction' => 'y']);

        self::assertNull($skill->allowedToolsClause([])); // 不限制
    }
}
