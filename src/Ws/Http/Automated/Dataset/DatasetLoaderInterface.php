<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Dataset;

/**
 * 数据源加载契约(design/23 §2.2):datasets 名字 → 记录列表。
 *
 * 注册点独立于 pause 的 ValueSource(design/22):一个面向"场景开始前的输入集",
 * 一个面向"场景中途取值",不合并。
 */
interface DatasetLoaderInterface
{
    /**
     * 加载记录列表。
     *
     * @param array<string, mixed> $spec datasets 里的对象形态(file/loader 参数)
     * @return array<int, array<string, mixed>> 记录列表(每条记录为 string→mixed 的 object)
     */
    public function load(array $spec): array;
}
