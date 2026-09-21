# ws-http 项目分析与重构设计文档

本目录是对 ws-http(PHP 5.4 时代代码)的完整分析与 PHP 7.4 重写设计文档。分两层:

## 第一层:现状分析(01–03,已完成)

| 文档 | 内容 |
| --- | --- |
| [01-现状分析与功能架构.md](01-现状分析与功能架构.md) | 现有代码的功能架构梳理、模块职责、依赖关系、Bug 与设计缺陷清单(B1–B11) |
| [02-需求文档.md](02-需求文档.md) | 按功能模块分类整理的完整需求(迁移期功能基线) |
| [03-重构方案与实施步骤.md](03-重构方案与实施步骤.md) | 初版迁移方案与阶段划分(已被 10–16 细化设计取代实施细节,保留作背景参考) |

## 第二层:目标架构细化设计(10–17,设计粒度到类/方法/字段)

> 阅读顺序建议:10(总纲,含 core/functional/plugin 三层与使用者画像)→ 11(core)→ 14(场景契约)→ 13(表达式)→ 12(断言)→ 15(变量)→ 16(执行器)→ 17(plugin)。
> 关键决策记录在 [10-总体架构设计.md](10-总体架构设计.md):抛弃 v1 历史包袱、Schema v2、JSONPath 子集、零外部依赖;设计总则 "Simple things should be simple, complex things should be possible"(§0,每份文档开头含简单路径示例);三层架构 core(cURL HTTP 工具)/ functional(断言·表达式·自动化引擎·变量提取)/ plugin(WordPress、OpenAI 等三方适配 + 注册机制)。

| 文档 | 层 | 内容 |
| --- | --- | --- |
| [10-总体架构设计.md](10-总体架构设计.md) | — | 设计总则、三层架构与使用者画像、命名空间与类清单、数据流、异常体系、错误码段 |
| [11-HTTP客户端设计.md](11-HTTP客户端设计.md) | core | Request/RequestOptions/HeaderBag/Response/Body 的 API 规格、默认值表、错误处理 |
| [12-断言器设计.md](12-断言器设计.md) | functional | Watcher 流式 + 收集式双形态、Comparison 操作符(可注册)、Assertion 值对象、失败消息模板 |
| [13-表达式引擎设计.md](13-表达式引擎设计.md) | functional | JSONPath 子集 EBNF 文法、求值语义(宽容求值)、错误码、测试用例集 |
| [14-场景脚本Schema v2.md](14-场景脚本Schema v2.md) | functional | 场景 JSON 正式契约:settings/steps/auth/proxy/body、校验规则 V1–V10、v1→v2 字段对照 |
| [15-变量系统设计.md](15-变量系统设计.md) | functional | VariableScope、${var} 深替换、extract 提取规则(source 可注册)与 onMissing 策略 |
| [16-执行器设计.md](16-执行器设计.md) | functional | Runner 状态机与 failFast、StepResult/Report(JSON 报告契约)、CookieStore、CLI 规格 |
| [17-Plugin层设计.md](17-Plugin层设计.md) | plugin | PluginInterface/PluginRegistry 注册机制、AuthProviderInterface、OpenAI/WordPress 内建样例 |
| [20-实施方案步骤.md](20-实施方案步骤.md) | — | 落地执行计划:S1–S11 步骤定义、目录结构、每步测试用例与完成标准 |
| [21-NanoGpt设计.md](21-NanoGpt设计.md) | NanoGpt | agent loop 引擎 + 工具协议(ToolInterface/Registry)+ Sandbox 沙箱 + 会话/用量;examples/cc-gpt CLI 壳 |
| [22-外部凭据注入设计.md](22-外部凭据注入设计.md) | functional | pause 步骤 + ValueSource 注册表(stdin/environment/poll);依赖等待与节奏等待的区分;时间参数只属于 poll |
| [23-数据驱动测试设计.md](23-数据驱动测试设计.md) | functional | Automated\Dataset 子模块:datasets/iterate、DatasetRunner 外层迭代、BatchReport 组维度汇总、CLI --dataset |
| [24-会话与记忆设计.md](24-会话与记忆设计.md) | NanoGpt | Conversation 演进(截断/快照/检查点)+ 会话持久化 + 上下文预算与压缩(/compact /rewind /clear /resume)+ 显式长期记忆(/memory) |

## 项目一句话概括

ws-http 是一个**轻量级 cURL HTTP 客户端库**,附带两个上层能力:

1. **Http Request**:发送各类 HTTP 请求(SSL / 认证 / 代理 / Cookie / 自定义头 / JSON / 表单 / Multipart),返回统一封装的 Response;
2. **Http Watcher**:对 Response 做断言(状态码 / 响应头 / 响应体 / 耗时 / JSON 比对),用于 HTTP API 测试;
3. **Automated**:基于 JSON 场景脚本的接口自动化测试引擎(v1 半成品;v2 设计见 14–16:变量、提取、断言、延时、报告、CLI)。
