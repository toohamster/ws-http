<?php

declare(strict_types=1);

namespace CcGpt\Command;

use CcGpt\CommandInterface;
use CcGpt\Context;

/**
 * /model:模型目录选择(design/21 §8.1)。
 *
 * 有 ModelSource → 列表 + 序号/名字切换;无 → 提示手动 /model <id> 或 /init。
 */
final class Model implements CommandInterface
{
    public function name(): string
    {
        return 'model';
    }

    public function description(): string
    {
        return 'List models (free ones first) and switch: /model [id|index]';
    }

    public function execute(array $args, Context $context): bool
    {
        $out = $context->output;

        // 切换形态
        if ($args !== []) {
            $target = implode(' ', $args);
            if ($context->modelSource !== null && preg_match('/^\d+$/', $target) === 1) {
                $models = $context->modelSource->models();
                $index = (int) $target - 1;
                if (!isset($models[$index])) {
                    $out->writeln(sprintf('no model at index %d', $index + 1), 'red');

                    return true;
                }
                $target = $models[$index]['id'];
            }
            $context->model = $target;
            $out->writeln('model → ' . $target, 'green');

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

        $headers = ['#', 'id', 'name'];
        $rows = [];
        $colors = ['gray', $context->model !== '' ? 'bright_cyan' : null, null];
        foreach ($models as $i => $info) {
            $mark = $info['id'] === $context->model ? '→' : (string) ($i + 1);
            $rows[] = [$mark, $info['id'], $info['name'] ?? ''];
        }
        $out->table($headers, $rows, ['gray', null, 'gray']);
        $out->writeln('switch: /model <index|id>', 'gray');

        return true;
    }
}
