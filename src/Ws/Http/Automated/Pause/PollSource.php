<?php

declare(strict_types=1);

namespace Ws\Http\Automated\Pause;

use Ws\Http\Automated\VarExtractor;
use Ws\Http\Automated\VariableScope;
use Ws\Http\Contract\ExtractorInterface;
use Ws\Http\RequestFactory;
use Ws\Http\Response;

/**
 * 可轮询取值策略(design/22 §3.2):包装一个 extract 提取器,按 interval 反复
 * 尝试直至有值或超时。唯一携带时间参数(interval/timeout)的策略。
 *
 * 设计:pending 期间的等待由本类内部实现(可注入 sleep,测试零延迟),
 * Runner 对轮询无感知、无新状态。
 */
final class PollSource implements ValueSource
{
    public const DEFAULT_INTERVAL = 5.0;
    public const DEFAULT_TIMEOUT = 300.0;

    /** @var ExtractorInterface 被包装的提取器(design/15 契约) */
    private $extractor;

    /** @var float 重试间隔(秒) */
    private $defaultInterval;

    /** @var float 总超时(秒) */
    private $defaultTimeout;

    /** @var callable(float $seconds): void sleep 替身(测试注入) */
    private $sleeper;

    /** @var callable(): float 时钟替身(测试注入) */
    private $clock;

    public function __construct(
        ExtractorInterface $extractor,
        array $defaults = [],
        ?callable $sleeper = null,
        ?callable $clock = null
    ) {
        $this->extractor = $extractor;
        $defaults = array_merge(['interval' => self::DEFAULT_INTERVAL, 'timeout' => self::DEFAULT_TIMEOUT], $defaults);
        $this->defaultInterval = (float) $defaults['interval'];
        $this->defaultTimeout = (float) $defaults['timeout'];
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            \sleep((int) ceil($seconds));
        };
        $this->clock = $clock ?? static function (): float {
            return \microtime(true);
        };
    }

    public static function id(): string
    {
        return 'poll'; // 实际 from 形态为 'poll:<name>',由 PauseRegistry 解析
    }

    /**
     * options: { interval?: float, timeout?: float, path?: string(提取定位器,传给被包装 extractor) }
     *
     * 值的最终选取由 Runner 的 extract 规则处理;本策略只回答"提取器此刻能否从
     * 探测请求拿到数据"。探测请求经 options.request 描述(可选;未提供时提取器
     * 拿到空响应,适合"注册表自定义 extractor 自行感知外部状态"的用法)。
     *
     * @param array<string, mixed> $options
     */
    public function fetch(array $options): ValueResult
    {
        $interval = isset($options['interval']) ? (float) $options['interval'] : $this->defaultInterval;
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : $this->defaultTimeout;
        $path = (string) ($options['path'] ?? '');

        $startedAt = ($this->clock)();
        $attempts = 0;

        while (true) {
            $attempts++;
            $outcome = $this->extractor->extract($this->probeResponse($options), $path);

            if ($outcome->found) {
                return ValueResult::got($outcome->value);
            }

            $elapsed = ($this->clock)() - $startedAt;
            if ($elapsed + $interval > $timeout) {
                return ValueResult::missing(sprintf(
                    'poll source timed out after %.0fs (%d attempts, interval %.0fs)',
                    $timeout,
                    $attempts,
                    $interval
                ));
            }

            ($this->sleeper)($interval);
        }
    }

    /**
     * 构造探测响应:提取器从 options.response(裸 curl_info + body)重建,或空 JSON 响应。
     *
     * @param array<string, mixed> $options
     */
    private function probeResponse(array $options): Response
    {
        if (isset($options['response']) && \is_array($options['response'])) {
            return new Response($options['response'], (string) ($options['rawBody'] ?? ''), '');
        }

        return new Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.0], '{}', '');
    }
}
