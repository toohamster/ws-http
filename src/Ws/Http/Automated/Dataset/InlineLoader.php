<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

/**
 * 内联数组加载器(design/23 §2.2 形态 A):spec 即记录列表,直通。
 */
final class InlineLoader implements DatasetLoaderInterface
{
    /**
     * @param array<string, mixed> $spec 内联形态即记录列表本身
     * @return array<int, array<string, mixed>>
     */
    public function load(array $spec): array
    {
        /** @var array<int, array<string, mixed>> */
        return $spec;
    }
}
