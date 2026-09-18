<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * NanoGpt 异常(design/21 §7):错误码段 600–699(design/10 §5)。
 *
 * 601 超 maxTurns / 602 未知名工具 / 603 工具注册重名 / 604 响应结构不符 / 610 沙箱路径越界
 */
final class AgentException extends \Ws\Http\Exception
{
}
