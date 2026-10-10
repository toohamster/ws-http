<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

use Ws\Http\NanoGpt\AgentException;
use Ws\Http\NanoGpt\ChatResult;
use Ws\Http\NanoGpt\ModelInfo;

/**
 * 传输契约(design/28 §2):编排层(Agent)唯一依赖——"能对话的东西"。
 *
 * 切口判据:编排层源码出现任何厂商名即失败;翻译责任(认证/端点/请求体/
 * 响应解析→ChatResult/错误语义分类/能力档案)完整落在实现里。
 *
 * 实现形态(design/28 §5 双层):协议族抽象基类(如 OpenAiCompatibleTransport)
 * 承载共性;每厂商一个具体类承载知识(baseUrl/默认模型/特殊逻辑覆写差异槽)。
 */
interface ChatTransportInterface
{
    /**
     * 一轮对话。
     *
     * @param array<int, array<string, mixed>> $messages OpenAI wire 格式(编排层通用语;
     *        协议级厂商由实现独自翻译,如 Claude 的 system 拆分/tool_use 块)
     * @param array<string, mixed> $options 采样参数(max_tokens/temperature)
     *        + retry 策略(§3):['retry' => ['max'=>int,'delay_ms'=>int,'multiplier'=>float,'on_status'=>int[]]]
     *        ——Retry-After 响应头存在时其秒数优先于退避计算;重试耗尽 → 最后一次错误进入错误映射(§4)
     * @throws AgentException 携带语义分类 code(620 auth / 621 rate-limit / 622 server /
     *         623 timeout / 624 unexpected-response)
     */
    public function chat(array $messages, array $options = []): ChatResult;

    /**
     * 能力档案:模型目录 + 静态能力声明。
     * ModelSourceInterface(design/21 §8.1/§8.2 时代产物)语义并入此处——对话域抽象收口。
     *
     * @return array{models: array<int, ModelInfo>, contextWindow: ?int, maxOutputTokens: ?int}
     */
    public function capabilities(): array;
}
