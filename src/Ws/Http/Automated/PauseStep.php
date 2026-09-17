<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 暂停取值步骤(design/22 §2):执行到此处流程挂起,从 ValueSource 取值写入 VariableScope。
 *
 * from: 'stdin' | 'environment' | 'poll:<name>' | 自定义策略 id;
 * 时间参数(interval/timeout)只属于 poll 策略,随 options 透传,本步骤零时间字段。
 */
final class PauseStep implements Step
{
    /** @var string */
    private $id;

    /** @var string */
    private $name;

    /** @var string 取值写入的变量名 */
    private $var;

    /** @var string 取值策略 id */
    private $from;

    /** @var array<string, mixed> 透传给策略的参数 */
    private $options;

    /** @var string 展示用说明 */
    private $prompt;

    /** @var array<int, array<string, mixed>> 可选:策略返回结构化数据时的提取规则(同 extract 语义) */
    private $extract;

    /**
     * @param array<string, mixed> $options
     * @param array<int, array<string, mixed>> $extract
     */
    public function __construct(
        string $id,
        string $name,
        string $var,
        string $from,
        array $options = [],
        string $prompt = '',
        array $extract = []
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->var = $var;
        $this->from = $from;
        $this->options = $options;
        $this->prompt = $prompt;
        $this->extract = $extract;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return 'pause';
    }

    public function var(): string
    {
        return $this->var;
    }

    public function from(): string
    {
        return $this->from;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(): array
    {
        return $this->extract;
    }
}
