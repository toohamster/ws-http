<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Ccgpt;

use CcGpt\Application;
use CcGpt\SkillDefinition;
use CcGpt\SkillLoader;
use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use PHPUnit\Framework\TestCase;
use Ws\Http\Tests\Fake\FakeChatTransport;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\ChatResult;

/**
 * design/26 §4/§5:/skill 命令(列表/--info/执行走 runAgentTurn)+ 会话兼容
 * (skill 消息经 runAgentTurn → auto-compact 链路,修订①)。
 */
final class SkillCommandTest extends TestCase
{
    private string $dir;
    private string $skillsDir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ws-skillcmd-' . uniqid();
        $this->skillsDir = $this->dir . '/skills';
        mkdir($this->skillsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(): Application
    {
        return new Application(
            $this->dir,
            new Output(fopen('php://memory', 'w+', false), false),
            new Input()
        );
    }

    private function writeSkill(array $spec): void
    {
        file_put_contents($this->skillsDir . '/' . $spec['name'] . '.skill.json', (string) json_encode($spec, JSON_UNESCAPED_UNICODE));
    }

    private function skillSpec(): array
    {
        return [
            'name'        => 'json-report',
            'description' => 'Analyze a JSON file',
            'instruction' => '分析 {path}:输出结构报告。不要修改任何文件。',
            'arguments'   => ['path' => ['type' => 'string', 'description' => 'file', 'required' => true]],
        ];
    }

    private function ctx(): \CcGpt\Context
    {
        $app = $this->app();
        $ctx = $app->context();
        $ctx->application = $app; // 构造里已回填,显式重申(测试可读性)

        return $ctx;
    }

    // ---------- /skill 列表 ----------

    public function testListShowsSkills(): void
    {
        $this->writeSkill($this->skillSpec());
        $ctx = $this->ctx();
        $command = new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir));

        $exit = $command->execute([], $ctx);

        self::assertTrue($exit); // 不退出 REPL
        // 无 agent 时列表照常(loader 不依赖装配)
    }

    public function testListEmptyDirShowsHint(): void
    {
        $ctx = $this->ctx();
        $command = new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir));

        $exit = $command->execute([], $ctx);

        self::assertTrue($exit);
    }

    // ---------- /skill name --info ----------

    public function testInfoShowsInstructionAndSlots(): void
    {
        $this->writeSkill($this->skillSpec());
        $ctx = $this->ctx();
        $command = new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir));

        $exit = $command->execute(['json-report', '--info'], $ctx);

        self::assertTrue($exit);
    }

    // ---------- /skill 执行(runAgentTurn 链路) ----------

    public function testExecuteRequiresAgent(): void
    {
        $this->writeSkill($this->skillSpec());
        $ctx = $this->ctx();
        $command = new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir));

        $exit = $command->execute(['json-report', 'data.json'], $ctx); // agent 未装配

        self::assertTrue($exit);
    }

    public function testExecuteRendersAndRunsAgentTurn(): void
    {
        // 真实 Application 的 agent 未配 key(bootstrap 提示后 return);注入测试 agent 验证链路
        $this->writeSkill($this->skillSpec());
        $ctx = $this->ctx();
        $ctx->agent = new Agent(new FakeChatTransport([
            new ChatResult('报告内容'),
        ]), 'm');

        $command = new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir));
        $exit = $command->execute(['json-report', 'data.json'], $ctx);

        self::assertTrue($exit);
        // skill 消息作为 user 消息进入会话(带前缀标记)
        $messages = $ctx->agent->conversation()->messages();
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('[skill: json-report] 分析 data.json:输出结构报告。不要修改任何文件。', $messages[0]['content']);
        // 渲染产出被 Agent 消费(文本回复已入会话)
        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('报告内容', $messages[1]['content']);
    }

    public function testExecuteMissingRequiredArgPrompts614(): void
    {
        $this->writeSkill($this->skillSpec());
        $ctx = $this->ctx();
        $ctx->agent = new Agent(new FakeChatTransport([new ChatResult('x')]), 'm');

        $command = new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir));
        $exit = $command->execute(['json-report'], $ctx); // 缺 required path

        self::assertTrue($exit);
        self::assertSame([], $ctx->agent->conversation()->messages()); // 不发请求(614 拦在渲染)
    }

    public function testExecuteUnknownSkillRejected(): void
    {
        $ctx = $this->ctx();
        $command = new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir));

        $exit = $command->execute(['nope', 'x'], $ctx);

        self::assertTrue($exit);
    }

    // ---------- 会话兼容(design/26 §5:普通 user 消息,零特殊处理) ----------

    public function testSkillMessageSurvivesSnapshotRestore(): void
    {
        $this->writeSkill($this->skillSpec());
        $ctx = $this->ctx();
        $ctx->agent = new Agent(new FakeChatTransport([new ChatResult('done')]), 'm');

        (new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir)))->execute(['json-report', 'a.json'], $ctx);

        // snapshot/restore 往返原样(skill 消息 = 普通 user 消息)
        $restored = \Ws\Http\NanoGpt\Conversation::restore($ctx->agent->conversation()->snapshot());
        self::assertSame('[skill: json-report] 分析 a.json:输出结构报告。不要修改任何文件。', $restored->messages()[0]['content']);
    }

    public function testSkillMessageCompactedLikeNormalUserMessage(): void
    {
        // auto-compact/手动 compact 视角:skill 消息就是普通 user 消息(修订①:天然过压缩)
        $this->writeSkill($this->skillSpec());
        $ctx = $this->ctx();
        $ctx->agent = new Agent(new FakeChatTransport([new ChatResult('done')]), 'm');
        (new \CcGpt\Command\Skill(new SkillLoader($this->skillsDir)))->execute(['json-report', 'a.json'], $ctx);
        $ctx->agent->conversation()->append(['role' => 'user', 'content' => 'q2']);
        $ctx->agent->conversation()->append(['role' => 'assistant', 'content' => 'a2']);

        // stub compactor:保留最近 2 条,其余摘要
        $compactor = new class implements \Ws\Http\NanoGpt\CompactorInterface {
            public function compact(array $messages): array
            {
                return ['role' => 'user', 'content' => '【历史摘要】earlier ' . count($messages) . ' msgs'];
            }
        };
        (new \CcGpt\Command\Compact($compactor))->execute(['1'], $ctx); // keep 1 轮

        $msgs = $ctx->agent->conversation()->messages();
        self::assertStringStartsWith('【历史摘要】', (string) $msgs[0]['content']); // skill 消息被摘要(无特殊处理)
        self::assertSame('q2', $msgs[1]['content']);
    }
}
