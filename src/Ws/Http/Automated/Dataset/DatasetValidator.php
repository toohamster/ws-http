<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

use Ws\Http\Automated\AutomatedException;

/**
 * 数据集校验(design/23 V16/V17):非空、记录为 object、同构(键集一致)。
 */
final class DatasetValidator
{
    /**
     * @param array<int, mixed> $records
     * @param string $context 错误消息前缀(如数据集名/文件路径)
     * @return array<int, array<string, mixed>>
     * @throws AutomatedException 422
     */
    public static function validate(array $records, string $context = 'dataset'): array
    {
        if ($records === []) {
            throw new AutomatedException(sprintf('%s is empty (V16)', $context), 422);
        }

        $keys = null;
        foreach ($records as $i => $record) {
            if (!\is_array($record) || $record === array_values($record)) { // 纯列表判定(7.4 无 array_is_list)
                throw new AutomatedException(sprintf('%s record #%d is not an object (V16)', $context, $i), 422);
            }

            $recordKeys = array_keys($record);
            sort($recordKeys);
            if ($keys === null) {
                $keys = $recordKeys;
                continue;
            }
            if ($keys !== $recordKeys) {
                throw new AutomatedException(
                    sprintf('%s record #%d has different keys than record #0 (V16 heterogeneity)', $context, $i),
                    422
                );
            }
        }

        /** @var array<int, array<string, mixed>> */
        return $records;
    }
}
