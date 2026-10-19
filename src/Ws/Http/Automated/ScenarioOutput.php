<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 场景输出取值(design/27 §3):场景作为取数函数的出口——纯函数,无执行职责。
 *
 * 场景(过程)+ outputs(返回值签名)+ 结果集(本出口)。不校验 Report 成败——
 * 调用方先判 isSuccess()(失败时的取数语义 = 调用方决策);结果集完整性以
 * 脚本成功为前提(onMissing=fail 的缺值键不会出现在结果集)。
 *
 * secret 语义:Report::variables() 终值快照在 Runner 内已经 Redactor 脱敏,
 * 结果集继承该形态——取数口不构成脱敏旁路。
 */
final class ScenarioOutput
{
    /**
     * 从运行结果提取声明的输出变量。
     *
     * @param Report $report 场景运行报告(variables 已脱敏)
     * @param string[] $outputs 场景 outputs(空数组 = 返回 {},调用方应先判场景是否声明)
     * @return array<string, mixed> 结果集(键序 = outputs 声明序;值 = 变量终值;未提取到的键缺席)
     * @throws AutomatedException 630 场景未声明 outputs(调用方误用,编程期暴露)
     */
    public function extract(Report $report, array $outputs): array
    {
        if ($outputs === []) {
            throw new AutomatedException(
                'scenario declares no outputs — ScenarioOutput::extract is meaningless without the outputs declaration',
                630
            );
        }

        $variables = $report->variables();
        $result = [];
        foreach ($outputs as $name) {
            if (array_key_exists($name, $variables)) {
                $result[$name] = $variables[$name];
            }
        }

        return $result;
    }
}
