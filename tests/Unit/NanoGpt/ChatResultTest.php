<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\ChatResult;

/**
 * design/28 §2(V2):ChatResult——chat 响应解析收口(传输契约的统一返回类型)。
 *
 * 钉住:全字段解析;tool_calls 数组化(wire 保留供回填);reasoning_content 捕获;
 * body null/结构残缺容错;usage 数值化。
 */
final class ChatResultTest extends TestCase
{
    public function testFromBodyFullFields(): void
    {
        $body = (object) [
            'choices' => [
                (object) [
                    'message'       => (object) [
                        'role'              => 'assistant',
                        'content'           => 'pong',
                        'reasoning_content' => 'thinking…',
                        'tool_calls'        => [
                            (object) [
                                'id'       => 'call_1',
                                'type'     => 'function',
                                'function' => (object) ['name' => 'write_file', 'arguments' => '{"path":"a.txt"}'],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => (object) ['prompt_tokens' => 91, 'completion_tokens' => 13, 'total_tokens' => 104],
        ];

        $r = ChatResult::fromBody($body);

        self::assertSame('pong', $r->content());
        self::assertSame('thinking…', $r->reasoningContent());
        self::assertSame('tool_calls', $r->finishReason());
        self::assertCount(1, $r->toolCalls());
        self::assertSame('call_1', $r->toolCalls()[0]['id']);
        self::assertSame('write_file', $r->toolCalls()[0]['name']);
        self::assertSame('{"path":"a.txt"}', $r->toolCalls()[0]['arguments']);
        self::assertSame(104, $r->usage()['total_tokens']);
        self::assertTrue($r->hasUsage());
    }

    public function testFromBodyNullBodyTolerant(): void
    {
        $r = ChatResult::fromBody(null);

        self::assertSame('', $r->content());
        self::assertNull($r->reasoningContent());
        self::assertNull($r->finishReason());
        self::assertSame([], $r->toolCalls());
        self::assertSame(0, $r->usage()['total_tokens']);
        self::assertFalse($r->hasUsage());
    }

    public function testFromBodyMalformedTolerant(): void
    {
        // choices 空数组 / message 缺失 / usage 非对象——全部兜底不抛
        $r = ChatResult::fromBody((object) ['choices' => [], 'usage' => 'bad']);

        self::assertSame('', $r->content());
        self::assertSame([], $r->toolCalls());
        self::assertSame(0, $r->usage()['total_tokens']);
    }

    public function testToolCallWirePreservedForBackfill(): void
    {
        $wire = (object) [
            'id'       => 'call_9',
            'type'     => 'function',
            'function' => (object) ['name' => 't', 'arguments' => '{}'],
        ];
        $body = (object) ['choices' => [(object) ['message' => (object) ['tool_calls' => [$wire]]]]];

        $r = ChatResult::fromBody($body);

        self::assertSame($wire, $r->toolCalls()[0]['wire']);
    }

    public function testMalformedToolCallEntrySkipped(): void
    {
        $body = (object) ['choices' => [(object) ['message' => (object) ['tool_calls' => ['not-an-object']]]]];

        self::assertSame([], ChatResult::fromBody($body)->toolCalls());
    }

    public function testUsageZeroStillCountsAsNoUsage(): void
    {
        $r = ChatResult::fromBody((object) ['choices' => [], 'usage' => (object) ['total_tokens' => 0]]);

        self::assertFalse($r->hasUsage());
    }
}
