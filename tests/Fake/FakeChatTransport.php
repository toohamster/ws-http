<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Fake;

use Ws\Http\NanoGpt\ChatResult;
use Ws\Http\NanoGpt\Transport\ChatTransportInterface;

/**
 * ChatTransportInterface 替身(tests/Fake 规格,design/28 D2)。
 *
 * 按序出队预制 ChatResult(编排层等价性测试:Agent loop 只依赖传输契约);
 * 可选固定抛出 AgentException(传输失败路径,code = 语义分类码 620–624)。
 * 捕获历次 (messages, options) 供断言(forRequest 形态/tools 字段有无)。
 */
final class FakeChatTransport implements ChatTransportInterface
{
    /** @var array<int, ChatResult> 预制结果序列(耗尽后停在末个) */
    private array $results;

    /** @var \Ws\Http\NanoGpt\AgentException|null 非空 = 每次 chat 直接抛(传输失败路径) */
    private $exception;

    /** @var array<int, array{messages: array, options: array}> 历次调用捕获 */
    public array $calls = [];

    /**
     * @param array<int, ChatResult> $results
     */
    public function __construct(array $results, ?\Ws\Http\NanoGpt\AgentException $exception = null)
    {
        $this->results = $results;
        $this->exception = $exception;
    }

    public function chat(array $messages, array $options = []): ChatResult
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->results[min(count($this->calls) - 1, count($this->results) - 1)];
    }

    /**
     * @return array{models: array, contextWindow: null, maxOutputTokens: null}
     */
    public function capabilities(): array
    {
        return ['models' => [], 'contextWindow' => null, 'maxOutputTokens' => null];
    }
}
