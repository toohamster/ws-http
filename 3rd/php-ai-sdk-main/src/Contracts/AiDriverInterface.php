<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Contracts;

use Generator;
use SnowmanNunu\Ai\DTO\AiResponse;
use SnowmanNunu\Ai\DTO\StreamChunk;

interface AiDriverInterface
{
    /**
     * 发送对话请求并返回完整响应。
     *
     * @param  array  $messages  消息数组，格式为 [['role' => 'user|assistant|system', 'content' => '...']]
     * @param  array  $options  可选参数，如 model、max_tokens、temperature 等
     */
    public function chat(array $messages, array $options = []): AiResponse;

    /**
     * 发送流式对话请求，返回生成器。
     *
     * @param  array  $messages  消息数组
     * @param  array  $options  可选参数
     * @return Generator<StreamChunk>
     */
    public function stream(array $messages, array $options = []): Generator;
}
