<?php

declare(strict_types=1);

namespace CcGpt;

use CcGpt\Ui\Input;
use CcGpt\Ui\Output;
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\ModelSourceInterface;
use Ws\Http\NanoGpt\Transport\ChatTransportInterface;

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

    /** @var ChatTransportInterface|null 主传输(与 agent 同凭据;bootstrapAgent 装配;压缩/探测共用,design/28 D3) */
    public $transport;

    /** @var string|null 当前 session 身份(壳持有;Conversation 不感知,design/24 §3) */
    public $currentSessionId;

    /** @var \Ws\Http\NanoGpt\ConversationStoreInterface|null 会话持久化(M4 装配) */
    public $sessionStore;

    /** @var \Ws\Http\NanoGpt\ContextBudget|null 当前窗口预算(模型能力档案驱动,M4 装配) */
    public $budget;

    /** @var bool 自动压缩开关(settings.compactAuto,默认 true) */
    public $compactAuto = true;

    /** @var \Ws\Http\NanoGpt\Memory|null 长期记忆(M4 装配) */
    public $memory;

    /** @var int|null 当前会话最近一次落盘后追加的消息序号(未保存提示用) */
    public $unsavedSince;

    /** @var \CcGpt\Application|null Application 回填(/skill 发起 agent 轮,design/26 §4) */
    public $application;

    /**
     * 把 Memory 的 systemPromptBlock 注入 Conversation(基线 system 之后拼记忆块)。
     */
    public function applyMemoryToSystem(): void
    {
        if ($this->agent === null || $this->memory === null) {
            return;
        }

        $base = $this->agent->conversation()->system();
        $baseContent = (string) ($base['content'] ?? '');
        $block = $this->memory->systemPromptBlock();
        $merged = $block !== null
            ? ($baseContent !== '' ? $baseContent . "\n\n" . $block : $block)
            : ($baseContent !== '' ? $baseContent : null);

        $this->agent->conversation()->setSystem($merged !== null ? ['role' => 'system', 'content' => $merged] : null);
    }

    public function __construct(Output $output, Input $input, Settings $settings)
    {
        $this->output = $output;
        $this->input = $input;
        $this->settings = $settings;
        $this->model = '';
    }
}
