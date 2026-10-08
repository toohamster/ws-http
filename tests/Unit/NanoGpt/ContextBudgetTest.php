<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\ContextBudget;

/**
 * design/24 §4.1(M2-a):ContextBudget——保守上界估算 + 80% 阈值 + 占用比。
 *
 * 估算公式:ceil(UTF-8 字节数 / 3);预算 = contextWindow - reserveForOutput。
 */
final class ContextBudgetTest extends TestCase
{
    private function budget(int $window = 128000, int $reserve = 4096): ContextBudget
    {
        return new ContextBudget($window, $reserve);
    }

    // ---------- estimateTokens(保守上界) ----------

    public function testEstimateAscii(): void
    {
        // 'hello' = 5 字节 → ceil(5/3) = 2;含 role 键开销按整条消息字节算
        $est = $this->budget()->estimateTokens([['role' => 'user', 'content' => 'hello']]);

        // 整条消息 JSON 序列化字节数 ÷ 3 向上取整
        $bytes = strlen(json_encode([['role' => 'user', 'content' => 'hello']], JSON_UNESCAPED_UNICODE));
        self::assertSame((int) ceil($bytes / 3), $est);
    }

    public function testEstimateChineseOverestimates(): void
    {
        // 中文 3 字节/字 → ÷3 = 1 token/字(真实中文常 <1 token/字 → 高估,方向正确)
        $est = $this->budget()->estimateTokens([['role' => 'user', 'content' => '你好世界']]);

        $bytes = strlen(json_encode([['role' => 'user', 'content' => '你好世界']], JSON_UNESCAPED_UNICODE));
        self::assertSame((int) ceil($bytes / 3), $est);
        self::assertSame((int) ceil($bytes / 3), $est); // 总字节 42(content 12 + 结构 30)→ ceil = 14
    }

    public function testEstimateEmptyMessages(): void
    {
        self::assertSame(0, $this->budget()->estimateTokens([]));
    }

    public function testEstimateSumsAcrossMessages(): void
    {
        $msgs = [
            ['role' => 'user', 'content' => 'aaaa'],      // 4B
            ['role' => 'assistant', 'content' => 'bbbb'], // 4B
        ];
        $est = $this->budget()->estimateTokens($msgs);

        $bytes = strlen(json_encode($msgs, JSON_UNESCAPED_UNICODE));
        self::assertSame((int) ceil($bytes / 3), $est);
    }

    public function testEstimateNeverUnderestimatesTrueTokenCount(): void
    {
        // 保守性:ASCII 真实 token 密度约 4 字节/token(0.25 t/B),公式给 0.33 t/B → 必然 ≥ 真实
        $ascii = 'The quick brown fox jumps over the lazy dog'; // 43 字节
        $est = $this->budget()->estimateTokens([['role' => 'user', 'content' => $ascii]]);
        $jsonBytes = strlen(json_encode([['role' => 'user', 'content' => $ascii]]));

        // 真实 token 数上界 ≈ 字节数/4(content 部分);估算应 ≥ 该值
        self::assertGreaterThanOrEqual((int) ($jsonBytes / 4), $est);
    }

    // ---------- needsCompact(≥80% 预算) ----------

    public function testNeedsCompactThreshold(): void
    {
        // window 1000, reserve 100 → 预算 900;80% = 720 tokens → 需要 ≥720×3 字节
        // 注意:估算含 JSON 结构开销(每条 +26B),故 content 字节取阈值字节 - 开销
        $b = $this->budget(1000, 100);
        $overhead = strlen(json_encode([['role' => 'user', 'content' => '']], JSON_UNESCAPED_UNICODE)); // 26
        $thresholdBytes = (int) (0.8 * 900) * 3; // 720 tokens → 2160 字节

        self::assertFalse($b->needsCompact($this->msgsWithBytes($thresholdBytes - $overhead - 3)));
        self::assertTrue($b->needsCompact($this->msgsWithBytes($thresholdBytes - $overhead)));
    }

    public function testNeedsCompactEmptyNever(): void
    {
        self::assertFalse($this->budget()->needsCompact([]));
    }

    // ---------- usageRatio(0–1) ----------

    public function testUsageRatio(): void
    {
        $b = $this->budget(1000, 0);
        $est = $b->estimateTokens($this->msgsWithBytes(300));

        self::assertSame($est / 1000, $b->usageRatio($this->msgsWithBytes(300)));
    }

    public function testUsageRatioClampedToOne(): void
    {
        $b = $this->budget(10, 0);

        self::assertSame(1.0, $b->usageRatio($this->msgsWithBytes(9000)));
    }

    /**
     * 造 content 恰为 N 字节 ASCII 的单条消息(总字节 = N + 结构开销,可控断言)。
     *
     * @return array<int, array<string, string>>
     */
    private function msgsWithBytes(int $contentBytes): array
    {
        return [['role' => 'user', 'content' => str_repeat('a', $contentBytes)]];
    }
}
