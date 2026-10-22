<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;
use Ws\Http\NanoGpt\SkillLoader;

/**
 * /skill(design/26 §4):任务级能力包——声明式指令模板渲染为本轮 user 消息。
 *
 *   /skill                 列出可用 skill(name + description)
 *   /skill name args...    装载 → 渲染(位置/名值参数)→ runAgentTurn(事件流)
 *   /skill name --info     预览指令模板原文与参数槽(不执行)
 *
 * 注入位 = user 消息(design/26 §3 评审钉死:随会话历史自然留存,持久化/压缩/
 * rewind 零特殊处理;不进 system——不与 Memory 冲突,M4 实证)。
 * 执行路径(design/26 §4 修订①):runAgentTurn 内 Compact::maybeAuto → Agent run
 * → renderEvent 事件流——skill 消息天然过自动压缩。
 * 名值参数形态:key:value(value 含空格时用引号包裹,如 path:"a b.json")。
 */
final class Skill implements CommandInterface
{
    /** @var SkillLoader */
    private $loader;

    public function __construct(SkillLoader $loader)
    {
        $this->loader = $loader;
    }

    public function name(): string
    {
        return 'skill';
    }

    public function description(): string
    {
        return 'Run a skill: /skill [name args... | name --info]';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        try {
            $skills = $this->loader->loadAll();
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            $out->writeln('skill load failed: ' . $e->getMessage(), 'red');

            return true;
        }

        // 无参:列出可用 skill
        if ($args === []) {
            return $this->listSkills($skills, $out);
        }

        $name = (string) array_shift($args);

        // --info:预览(移除标志位后仍可能有其他参数,预览态忽略)
        $isInfo = false;
        foreach ($args as $i => $a) {
            if ($a === '--info') {
                $isInfo = true;
                unset($args[$i]);
            }
        }
        if ($isInfo) {
            return $this->showInfo($name, $skills, $out);
        }

        // 执行
        if (!isset($skills[$name])) {
            $out->writeln(sprintf('unknown skill "%s" — /skill to list', $name), 'red');

            return true;
        }
        if ($context->agent === null) {
            $out->writeln('agent not configured — run /init first', 'yellow');

            return true;
        }

        $skill = $skills[$name];

        // 渲染(required 缺失 → 614,提示补参,不发请求)
        try {
            $message = $skill->render($this->parseArgs($args));
            $clause = $skill->allowedToolsClause($context->agent->tools()->names());
            if ($clause !== null) {
                $message .= "\n" . $clause;
            }
        } catch (\Ws\Http\NanoGpt\AgentException $e) {
            $out->writeln($e->getMessage(), 'red');

            return true;
        }

        foreach ($skill->warnings() as $warning) {
            $out->writeln('skill warning: ' . $warning, 'yellow');
        }

        // 作为本轮 user 消息发 Agent run(与普通输入同一条路径:自动压缩/事件流全兼容)
        $out->writeln(sprintf('▶ skill %s', $name), 'cyan');
        $context->application->runAgentTurn($message);

        return true;
    }

    /**
     * @param array<string, SkillDefinition> $skills
     */
    private function listSkills(array $skills, \CcGpt\Ui\Output $out): bool
    {
        if ($skills === []) {
            $out->writeln(sprintf('no skills (put *.skill.json into %s)', $this->loader->dir()), 'yellow');

            return true;
        }
        foreach ($skills as $name => $skill) {
            $out->writeln(sprintf('%-16s %s', $name, $skill->description()));
        }
        $out->writeln('run: /skill <name> [args...]   preview: /skill <name> --info', 'gray');

        return true;
    }

    /**
     * 命令参数解析:位置参数直传;key:value 形态转名值对(value 可引号包裹含空格)。
     *
     * @param array<int, string> $args
     * @return array<int|string, string>
     */
    private function parseArgs(array $args): array
    {
        $parsed = [];
        foreach ($args as $arg) {
            $arg = (string) $arg;
            // key:"value with spaces" / key:value
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $arg, $m) === 1) {
                $value = trim($m[2]);
                // 引号包裹剥除(value 含空格场景)
                if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                }
                $parsed[$m[1]] = $value;
            } else {
                $parsed[] = $arg;
            }
        }

        return $parsed;
    }

    /**
     * --info:模板原文与参数槽预览。
     *
     * @param array<string, SkillDefinition> $skills
     */
    private function showInfo(string $name, array $skills, \CcGpt\Ui\Output $out): bool
    {
        if (!isset($skills[$name])) {
            $out->writeln(sprintf('unknown skill "%s" — /skill to list', $name), 'red');

            return true;
        }
        $skill = $skills[$name];

        $out->writeln(sprintf('skill %s (%s)', $skill->name(), $skill->sourceFile()), 'cyan');
        if ($skill->description() !== '') {
            $out->writeln($skill->description());
        }
        if ($skill->arguments() !== []) {
            $out->writeln('arguments:', 'gray');
            foreach ($skill->arguments() as $slot => $def) {
                $req = $def['required'] ? 'required' : 'optional';
                $default = $def['default'] !== null ? sprintf(' [default: %s]', $def['default']) : '';
                $out->writeln(sprintf('  {%s} %s — %s%s', $slot, $req, $def['description'], $default));
            }
        }
        if ($skill->allowedTools() !== null) {
            $out->writeln('allowedTools: ' . implode(', ', $skill->allowedTools()), 'gray');
        }
        if ($skill->warnings() !== []) {
            foreach ($skill->warnings() as $warning) {
                $out->writeln('warning: ' . $warning, 'yellow');
            }
        }
        $out->writeln('instruction:', 'gray');
        $out->writeln('  ' . $skill->instruction());

        return true;
    }
}
