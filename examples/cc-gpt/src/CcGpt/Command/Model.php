<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /model:模型目录选择 + 切换对账(design/21 §8.2 + design/24 §6.1)。
 *
 * 有 ModelSource → 列表(id/name/窗口列;窗口 null 显示 "-")+ 序号/名字切换;
 * 选择后窗口未知 → 探测提示词作为默认值,用户 [录入/自设/跳过](§8.2 级联);
 * 切换后执行 §6.1 对账(写入档案 → 重建 Budget → 三态:正常/警告/超限菜单 c/r/k);
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

            $previousModel = $context->model;
            $context->model = $target;
            $out->writeln('model → ' . $target, 'green');

            // 窗口未知 → 探测默认值,用户裁决(§8.2 级联:录入/自设/跳过)
            if ($selected !== null && $selected->contextWindow() === null) {
                $this->probeContextWindow($selected->id(), $context);
            }

            // §6.1 切换对账(design/24):重建 Budget → 三态
            $this->reconcile($previousModel, $context);

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
     * §6.1 切换对账(design/24):重建 Budget(新模型档案)→ 当前 messages 估算 → 三态:
     *   a) 未超 80% → 一行确认(window + usage %);
     *   b) ≥ 80% 未超窗 → 警告(不强制);
     *   c) 超 100%(预算口径)→ 菜单 [c]ompact / [r]ewind / [k]eep。
     * 无 agent(纯列表浏览切换)或无窗口档案 → 跳过对账(切回 Budget 由 buildBudget 档案驱动)。
     */
    private function reconcile(string $previousModel, Context $context): void
    {
        $out = $context->output;
        if ($context->agent === null || $context->application === null) {
            return;
        }

        // Budget 重建(窗口 = 新模型档案;Application::buildBudget 为 M4 既有逻辑,公开复用)
        $context->budget = $context->application->buildBudget($context->model);
        if ($context->budget === null) {
            return; // 无窗口档案且无兜底(buildBudget 恒返回,防御)
        }

        $messages = $context->agent->conversation()->messages();
        $estimated = $context->budget->estimateTokens($messages);
        $ratio = $context->budget->usageRatio($messages);
        $window = $this->currentWindow($context);
        $usage = sprintf('~%.0f%%', $ratio * 100);

        // 三态判定(design/24 §6.1)
        if ($ratio < 0.8) {
            // a) 正常
            $out->writeln(sprintf('switched to %s (window %s, context usage %s)', $context->model, $window, $usage), 'gray');

            return;
        }

        $overBudget = $context->budget->needsCompact($messages); // 预算口径(扣输出预留)超限
        if (!$overBudget) {
            // b) ≥80% 未超预算 → 警告不强制
            $out->writeln(sprintf('warning: usage %s of window %s — consider /compact', $usage, $window), 'yellow');

            return;
        }

        // c) 超预算 → 对账菜单 c/r/k
        $out->writeln(sprintf('context usage %s exceeds budget for %s (window %s, est. %d tokens)', $usage, $context->model, $window, $estimated), 'yellow');
        $this->overBudgetMenu($context);
    }

    /**
     * 超限菜单(design/24 §6.1 c 态):[c]ompact / [r]ewind / [k]eep。
     */
    private function overBudgetMenu(Context $context): void
    {
        $out = $context->output;
        $out->writeln('  [c]ompact  auto-compact (keep 2 turns)' . ($context->compactAuto ? '  ← recommended' : ''), 'gray');
        $out->writeln('  [r]ewind   back to an earlier checkpoint', 'gray');
        $out->writeln('  [k]eep     keep as-is (next request may be rejected by the server)', 'gray');

        $line = $context->input->readLine('choice [c/r/k]: ');
        $answer = $line === null ? 'k' : strtolower(trim((string) $line));
        $choice = $answer === '' ? 'k' : $answer[0];

        switch ($choice) {
            case 'c':
                $compactor = new \Ws\Http\NanoGpt\LlmCompactor(
                    $context->transport ?? new \Ws\Http\NanoGpt\Transport\GenericOpenAiTransport(['api_key' => 'unset']),
                    $context->model !== '' ? $context->model : 'gpt-4o-mini'
                );
                $done = (new Compact($compactor, $context->sessionStore))->run($context, 2, true);
                if (!$done) {
                    $out->writeln('nothing to compact — keeping', 'yellow');
                }
                break;
            case 'r':
                (new Rewind())->execute([], $context);
                break;
            default:
                $out->writeln('kept — next request may fail if context exceeds the new model window', 'yellow');
        }
    }

    /**
     * 当前窗口值(Budget 内部私有;对账展示用,与 buildBudget 同源档案读取)。
     */
    private function currentWindow(Context $context): string
    {
        $profiles = $context->settings->get('modelProfiles');
        $window = \is_array($profiles) && isset($profiles[$context->model]['contextWindow']) && \is_int($profiles[$context->model]['contextWindow'])
            ? $profiles[$context->model]['contextWindow']
            : (int) $context->settings->get('contextWindow', 128000);

        return $window >= 1000 ? sprintf('%dk', (int) round($window / 1000)) : (string) $window;
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
            if ($context->transport === null) {
                $out->writeln('context window unknown — set manually in settings (contextWindow)', 'yellow');

                return;
            }
            $probe = new \CcGpt\ContextWindowProbe($context->transport);
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
