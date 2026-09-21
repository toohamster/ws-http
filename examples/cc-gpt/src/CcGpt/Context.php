<?php

declare(strict_types=1);

namespace CcGpt;

use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\ModelSourceInterface;

/**
 * 命令执行上下文:壳内共享物(REPL 状态 + 组件句柄)。
 */
final class Context
{
    /** @var Output */
    public $output;

    /** @var Input */
    public $input;

    /** @var Agent|null 未配置 key 前为 null */
    public $agent;

    /** @var string 当前模型 id */
    public $model;

    /** @var ModelSourceInterface|null /model 的目录来源(null = 手动指定) */
    public $modelSource;

    /** @var Settings */
    public $settings;

    /** @var CommandRegistry|null Application 装配后回填(Help 消费) */
    public $commands;

    /** @var string 引擎草稿区(.runtime;agent 中间脚本/临时文件) */
    public $runtimeDir;

    public function __construct(Output $output, Input $input, Settings $settings)
    {
        $this->output = $output;
        $this->input = $input;
        $this->settings = $settings;
        $this->model = '';
    }
}
