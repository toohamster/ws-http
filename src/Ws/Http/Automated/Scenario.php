<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Method;

/**
 * 场景对象(design/14 §1):ScenarioParser 的解析产物,Runner 的执行输入。
 */
final class Scenario
{
    /** @var string */
    public $id;

    /** @var string */
    public $name;

    /** @var string 描述(可选) */
    public $description;

    /** @var array{timeout: string, failFast: bool, cookieStore: string, redirect?: array{follow: bool, max: int}} 场景级默认 */
    public $settings = [
        'timeout'     => '30s',
        'failFast'    => true,
        'cookieStore' => 'memory',
    ];

    /** @var array<int, array{name: string, value: mixed, secret?: bool}> */
    public $variables = [];

    /** @var array<int, HttpStep|DelayStep|PauseStep> */
    public $steps = [];

    /** @var array<string, mixed> datasets 原始声明(名 → 内联数组或 {file|loader} 对象,design/23 §2.1) */
    public $datasets = [];
}
