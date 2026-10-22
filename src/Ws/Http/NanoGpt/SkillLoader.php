<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

use Ws\Http\NanoGpt\AgentException;

/**
 * Skill 装载器(design/26 §2/§4):扫描 skills/ 下 *.skill.json → SkillDefinition。
 *
 * 与 design/25 §4 DescriptorToolLoader 同源骨架(单文件非法 → 613 跳过继续,
 * 错误写 sidecar 日志;跨文件重名 → 613 提示两个文件名,S1 查重在 loadAll 批量期)。
 * **skills/ 不存在 = 零 skill,不自动创建**(design/26 §4 修订 P3:用户资产 vs
 * tools/ 草稿区的自觉差异——空目录无意义,自动创建制造仓库噪音)。
 */
final class SkillLoader
{
    /** @var string skills 目录(壳根) */
    private $dir;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * @return array<string, SkillDefinition> name → definition(装载序)
     */
    public function loadAll(): array
    {
        if (!is_dir($this->dir)) {
            return []; // P3:用户资产目录不存在 = 零 skill(不 mkdir)
        }

        $skills = [];
        $sources = [];

        foreach (glob($this->dir . '/*.skill.json') ?: [] as $file) {
            try {
                $skill = $this->loadFile((string) $file);
            } catch (AgentException $e) {
                // 单文件非法:跳过继续(同源 design/25 §4);错误不吞:sidecar 日志供壳展示
                @file_put_contents($this->dir . '/.loader-errors.log', sprintf("[%s] %s\n", date('c'), $e->getMessage()), FILE_APPEND);
                continue;
            }

            if (isset($sources[$skill->name()])) {
                throw new AgentException(
                    sprintf('skill name "%s" duplicated (in %s and %s)', $skill->name(), $sources[$skill->name()], basename((string) $file)),
                    613
                );
            }
            $sources[$skill->name()] = basename((string) $file);
            $skills[$skill->name()] = $skill;
        }

        return $skills;
    }

    public function loadFile(string $file): SkillDefinition
    {
        $raw = @\file_get_contents($file);
        if ($raw === false) {
            throw new AgentException(sprintf('cannot read skill "%s"', $file), 613);
        }

        $spec = json_decode($raw, true);
        if (!\is_array($spec)) {
            throw new AgentException(sprintf('skill "%s" is not valid JSON', basename($file)), 613);
        }

        foreach (['name', 'instruction'] as $required) {
            if (!isset($spec[$required]) || (string) $spec[$required] === '') {
                throw new AgentException(sprintf('skill "%s": missing "%s"', basename($file), $required), 613);
            }
        }

        return new SkillDefinition($spec, basename($file));
    }
}
