<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /model:模型目录选择(design/21 §8.2 ModelInfo 属性模型)。
 *
 * 有 ModelSource → 列表(id/name/窗口列;窗口 null 显示 "-")+ 序号/名字切换;
 * 选择后窗口未知 → 探测提示词作为默认值,用户 [录入/自设/跳过](§8.2 级联);
 * 无 → 提示手动 /model <id> 或 /init。
 */
final class Model implements CommandInterface
{
    public function name(): string
    {
        return 'model';
    }

    public function description(): string
    {
        return 'List models and switch: /model [id|index]';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        // 切换形态
        if ($args !== []) {
            $target = implode(' ', $args);
            $selected = null;
            if ($context->modelSource !== null && preg_match('/^\d+$/', $target) === 1) {
                $models = $context->modelSource->models();
                $index = (int) $target - 1;
                if (!isset($models[$index])) {
                    $out->writeln(sprintf('no model at index %d', $index + 1), 'red');

                    return true;
                }
                $selected = $models[$index];
                $target = $selected->id();
            }
            $context->model = $target;
            $out->writeln('model → ' . $target, 'green');

            // 窗口未知 → 探测默认值,用户裁决(§8.2 级联:录入/自设/跳过)
            if ($selected !== null && $selected->contextWindow() === null) {
                $this->probeContextWindow($selected->id(), $context);
            }

            return true;
        }

        // 无来源:手动指定提示
        if ($context->modelSource === null) {
            $out->writeln('no model source configured (set model manually: /model <id>)', 'yellow');

            return true;
        }

        // 列表形态
        $models = $context->modelSource->models();
        if ($models === []) {
            $out->writeln('model list is empty', 'yellow');

            return true;
        }

        $headers = ['#', 'id', 'name', 'window'];
        $rows = [];
        foreach ($models as $i => $info) {
            $mark = $info->id() === $context->model ? '→' : (string) ($i + 1);
            $rows[] = [$mark, $info->id(), $info->name(), $this->windowCell($info)];
        }
        $out->table($headers, $rows, ['gray', null, 'gray', 'gray']);
        $out->writeln('switch: /model <index|id>', 'gray');

        return true;
    }

    /**
     * 窗口列(有值 = k 格式化;null = "-")。
     */
    private function windowCell(\Ws\Http\NanoGpt\ModelInfo $info): string
    {
        $w = $info->contextWindow();
        if ($w === null) {
            return '-';
        }

        return $w >= 1000
            ? sprintf('%dk', (int) round($w / 1000))
            : (string) $w;
    }

    /**
     * 探测提示词(§8.2 级联层 2):模型自述上下文参数 → 默认值 → 用户 [录入/自设/跳过]。
     */
    private function probeContextWindow(string $modelId, Context $context): void
    {
        $out = $context->output;
        if ($context->agent === null) {
            $out->writeln('context window unknown — set manually in settings (contextWindow)', 'yellow');

            return;
        }

        $out->writeln('probing context window (model self-report, may be inaccurate)…', 'gray');
        try {
            $probe = new \CcGpt\ContextWindowProbe($context->probeClient);
            $estimate = $probe->probe($modelId);
        } catch (\Throwable $e) {
            $out->writeln('probe failed: ' . $e->getMessage() . ' — set manually (contextWindow)', 'yellow');

            return;
        }

        $out->writeln(sprintf('model self-report: window ≈ %d tokens', $estimate), 'gray');
        $line = $context->input->readLine('use as default? [y]es / [n]o (set manually) / [s]kip: ');
        $answer = $line === null ? 's' : strtolower(trim($line));
        if ($answer === '' || $answer === 's' || $answer === 'skip') {
            $out->writeln('skipped — window stays unknown (set manually: settings.contextWindow)', 'yellow');

            return;
        }
        if ($answer === 'n' || $answer === 'no') {
            $manual = $context->input->readLine('context window (tokens): ');
            if ($manual === null || trim($manual) === '' || !preg_match('/^\d+$/', trim($manual))) {
                $out->writeln('invalid number — window stays unknown', 'yellow');

                return;
            }
            $estimate = (int) trim($manual);
        }

        // 落定:按模型 id 写档案(provenance = manual,经用户裁决)
        $profiles = $context->settings->get('modelProfiles');
        $profiles = \is_array($profiles) ? $profiles : [];
        $profiles[$modelId] = ['contextWindow' => $estimate, 'provenance' => 'manual'];
        $context->settings->set('modelProfiles', $profiles);
        $context->settings->save();
        $out->writeln(sprintf('context window for %s set to %d (manual)', $modelId, $estimate), 'green');
    }
}
