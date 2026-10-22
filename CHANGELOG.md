# Changelog

所有显著变更记录于此。格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

## [2.8.0] - 2026-10-22

### Added
- Skill 任务级能力包(design/26):`skills/*.skill.json` 声明式定义(name + 指令模板 + 参数槽 + 可选 allowedTools)——`/skill` 命令三形态(无参列表 / 渲染执行 / `--info` 预览);校验 S1–S4(装载违例 613,required 缺参 614 拦在渲染不发请求);注入位 = user 消息(随会话历史自然留存,持久化/压缩/rewind 零特殊处理,天然过自动压缩);SkillLoader 与 design/25 §4 同源(单文件非法跳过 + sidecar 日志,跨文件重名 613);allowedTools 约束渲染进指令文本(提示性,安全兜底在工具层);内建示例 skills/json-report.skill.json
- cc-gpt 装配:`/skill` 命令注册;`Application::runAgentTurn` 公开化(命令可发起 agent 轮);skills/ 为用户资产随壳入库(目录不存在 = 零 skill,不自动创建)

## [2.7.1] - 2026-10-21

### Changed
- 发布工程:release-workflow 新增铁律——已发布标签不可移动(不 force push tag/不删重打;tag 后发现的遗漏一律进位新版本;打 tag 是流程最后一步)。来源:v2.7.0 tag 移位触发 Packagist stable 不可变告警,tag 已恢复原 commit `660edf2`
- README/composer 定位描述对齐 v2.7.0(能力表四层/Transport 示例/outputs 取数形态/keywords 补检索词);design/26 Skill 修订(对齐 v2.7.0 现状 + review P1–P4,实现已确认启动);design/PROGRESS.md 台账与悬置清单落档

## [2.7.0] - 2026-10-20

### Added
- 场景输出契约(design/27,O1–O2):场景作为取数函数——顶层 `outputs` 白名单(校验 V19:字符串数组/唯一/可产出,违例 402)+ `ScenarioOutput::extract` 结果集出口(键序 = 声明序;未声明/空 → 630;secret 脱敏继承 Report 形态)
- `ApiFetchTool` 场景形态:`{scenario, vars?, outputs?}` 双形态取数(单请求形态保留)——场景文件 → Runner 执行 → 声明输出提取;白名单前置校验(执行前拒绝);vars 只覆盖已声明变量、outputs 只收窄不扩充;失败返回 failedSteps 明细
- `StaticRequestFactory`(注入式工厂):场景引擎复用注入 http(工具集成/测试装配面)
- 设计进度台账 `design/PROGRESS.md`:全设计落地状态/实施步骤完成度/收口 tag 一页表 + 悬置工作清单 + 版本时间线

### Changed
- README/composer 定位描述对齐 v2.7.0:能力表"三层"→"四层"(NanoGpt 多厂商 agent 编排独立成行 + Transport 代码示例);场景示例补 outputs 取数形态;keywords 补 llm/ai-agent/openai(Packagist 检索面)
- design/26 Skill 修订(对齐 v2.7.0 现状 + review P1–P4):执行路径补 M4 自动压缩链路、错误码 613/614 沿用 design/25、注入位 Memory 无冲突经 M4 实证、发布方式改纯 semver、触发条件标注已满足——**实现已确认启动**

## [2.6.0] - 2026-10-16

### Added
- 厂商接入层(design/28):`NanoGpt\Transport\` 子命名空间——`ChatTransportInterface`(chat + capabilities,ModelSource 语义并入)、`OpenAiCompatibleTransport` 协议族抽象基类(重试完整语义:Retry-After 头优先/指数退避/on_status;错误语义分类 620–624:auth/rate-limit/server/timeout/unexpected-response)、每厂商一个具体类(OpenAi/DeepSeek/Qwen/Kimi/Moonshot/MiniMax/OrcaRouter,覆盖常量即接入)+ `GenericOpenAiTransport` 逃生门(config 注入 baseUrl/default_model)
- `ChatResult` 值对象:chat 响应解析收口(容错解析 + tool_call wire 保留 + reasoning_content 兼容)
- Agent/壳换契约(design/28 D2/D3):Agent 构造收 `ChatTransportInterface`(编排层源码零厂商名);传输失败以语义分类 code 透传 error 事件;model/tools 请求级透传(options['model'] 覆盖厂商默认)
- cc-gpt 装配切换:settings.adapter → Transport 厂商类(共享实例:Agent/压缩/探测);ModelProvider 适配器薄壳化(目录解析下沉 Transport::modelFromEntry 差异槽,free 过滤/pricing 归一落厂商类)

### Changed
- `LlmCompactor`/cc-gpt `ContextWindowProbe` 传输依赖 Client → ChatTransportInterface
- examples/cc-gpt/bin/smoke 三链重写为 Transport 全链(capabilities/chat/tools)
- 兼容性:`Agent` 构造签名变更(Client → ChatTransportInterface);`Plugin\OpenAI\Client` 保持不动(人的业务对接面,design/17 定位)

### Verified
- 真实链冒烟 3/3(OrcaRouterTransport→Agent,deepseek/deepseek-v4-flash-free:models 6 free/chat usage=112/tools write_file 全链)
- 全量 559 tests / 1323 assertions;PHPStan 0 error

## [2.5.0] - 2026-10-08

### Added
- 会话管理组件(design/24):`Conversation` 增强(setSystem 运行期注入 / truncateFrom user 边界截断 605 / snapshot v:1 + restore 611 / userCheckpoints 摘要标记)、`ConversationStoreInterface` + `FileConversationStore`(.runtime/sessions,新→旧,损坏容错)
- 上下文预算与压缩:`ContextBudget`(ceil(bytes/3) 保守估算,80% 阈值)、`CompactorInterface` + `LlmCompactor`(tool 结果预截断 2000 字符防死锁,失败 612 原 messages 不动)
- 显式长期记忆:`Memory`(remember/recall/forget/systemPromptBlock,经 setSystem 即时生效)
- 壳命令族(cc-gpt):/compact /rewind /clear /resume /memory + 每轮 run 前自动压缩(compactAuto 可关)+ /context 双口径(累计 usage + 窗口占用)
- cc-gpt 模型服务适配:`ModelInfo` 属性模型(id/name/pricing + contextWindow/maxOutputTokens + provenance 来源标记)、ModelProvider 三层适配器(接口 + AbstractServiceAdapter 模板 + OrcaRouter/Generic/Static 实例)、/init 适配器选择分派、模型能力参数级联(接口字段 → 选择后提示词探测默认值 → 用户裁决)
- `PreparedBody::__toString`(字符串直返;数组转 JSON 表示,日志/诊断用)

### Fixed
- cc-gpt 壳不再无条件注入 OrcaRouter 模型来源(orcarouter 特定逻辑默认化的架构错误,§8.2 评审)
- `Input::readLine` 注入流形态提示词改走 STDERR(原写进读流污染读取位置;生产分支未动)
- bin/smoke 汇总计数(record() 函数作用域遮蔽)+ chat 链失败诊断

### Verified
- N4 集成冒烟 3/3(deepseek-v4-flash-free,function calling 证实;orcarouter/free 配额池共享不作优先选择)

## [2.4.0] - 2026-10-08

### Added
- design/21 §8.2 收口:ModelProvider 三层适配器 + /init 显式选择(settings.adapter 分派,host 嗅探否决)
- N4 冒烟脚本归位 examples/cc-gpt/bin/smoke(三链:models/chat/tools,配置与壳同源);孤儿 config.example.php 删除

## [2.0.0-alpha.8] - 2026-09-15

### Added
- examples/ 开发者示例集:core(httpbin-quick)/ functional(scenario)/ plugin(openai-chat,已对千帆兼容端点实测)

## [2.0.0-alpha.7] - 2026-09-15

### Added
- CLI `bin/ws-http run`:退出码 0/1/2,--format=text|json,--report-out,--no-fail-fast,--var=name=value,--stop-on-parse-error
- plugin 层:`PluginInterface`/`PluginContext`/`AuthProviderInterface` 契约 + `PluginRegistry`(重名 501/未知 auth 502)
- 内建 plugin:OpenAI(Bearer,chat/models)、WordPress(Application Password,posts/media,isWpError)
- 隔离性测试:core/functional 源码不得引用 Plugin 命名空间

## [2.0.0-alpha.6] - 2026-09-14

### Added
- 场景引擎:`ScenarioParser`(V1–V11 校验,JSON Pointer 错误)、`Scenario`/`HttpStep`/`DelayStep`
- `Runner`:resolve→send→extract→assert 管线、failFast/skipped 状态机、三级配置合并、CookieStore(memory/none)
- `Report` JSON 报告契约;`StepResult`;secret 变量输出脱敏(Redactor)
- Engine 测试套件(mock RequestFactory)+ 夜点预订流程端到端 fixture(含 B4 回归)

## [2.0.0-alpha.5] - 2026-09-14

### Added
- 变量系统:`VariableScope`(undefined≠null≠空串三态;secret 标记)、`ScenarioResolver`(${var} 深替换,类型保留)、`VarExtractor`(六内建 source 注册表 + onMissing fail/skip/default)
- `Redactor` 敏感值脱敏;`Contract\ExtractorInterface`

## [2.0.0-alpha.4] - 2026-09-14

### Added
- `Support\ResultSet` 通用结果容器(passed/failed/filter/toArray)
- `Contract\FnComparator` 闭包包装(用户函数一行接入操作符注册表)
- Extension vs Plugin 边界定界(design/12 §2.1):表达式文法不开放注入,非 JSON 经 source 层扩展

## [2.0.0-alpha.3] - 2026-09-11

### Added
- 断言层:`Watcher` 流式(链式,失败即抛 301–305)、`AssertionRunner` 收集式(不短路)、`Comparison` 操作符注册表(eq 数值化/数值比较/包含/正则/长度/类型等 15+ 内建)
- `Contract\ComparatorInterface`

### Fixed
- **assertBody 包含匹配参数颠倒**(1.x Watcher.php:118 strpos($asserted, $body) → strpos($actual, $expected))
- assertHeaders/头断言大小写不敏感(1.x 敏感)
- IS_VALID_JSON 用 json_last_error 判定(1.x 把合法 "null"/"0" 体误判失败)
- 移除命名空间内全局函数 isAssoc/prettyPrintJson(1.x B7),JSON 比对改用 JSON_PRETTY_PRINT

## [2.0.0-alpha.2] - 2026-09-11

### Added
- `StrKit::extract()`:**{name} 模板提取**(声明式替代裸正则),作为 template source 的 core 实现
- 修复空结束分隔符模式下分隔符被占位符值吞掉的问题

## [2.0.0-alpha.1] - 2026-09-11

### Added
- JSONPath 子集表达式引擎:点路径/索引(含负)/切片(Python 语义)/联合/通配/filter(比较+existence+正则)/引号键/UTF-8 键;宽容求值(结构不匹配→空集不抛);解析 memoize
- 断言与变量提取共用同一求值器(单一事实来源)

## [2.0.0-alpha.0] - 2026-09-11

### Added(core 层)
- `Request`:cURL 引擎,send() 唯一咽喉点,executeCurl 可测缝,wither 配置派生
- `RequestOptions`:不可变配置值对象(默认超时 30s;proxy port 校验)
- `HeaderBag`:大小写不敏感头集合(多值合并/续行解析/小写输出)
- `Response`:statusLine/jsonError 解析诊断/isOk/totalTime
- `Body`+`PreparedBody`:json/form/multipart/file/raw(自动 Content-Type,用户显式头优先)
- `UrlKit`:buildHttpQuery 拍平 + encodeUrl(非 ASCII 预编码,规避 parse_url 字节不安全)
- `Method`(IANA 全集)+ `MethodHelper::isBodyAllowed`
- 异常体系:`Exception`(错误码分段)← `RequestException`(errno/error/url/method)

### Changed(自 1.x 的行为变更)
- **PHP 7.4+**,全库 strict_types;移除 PHP 5.x 兼容与 PECL http 依赖
- **默认超时 30s**(1.x 无限等待)
- **SSL CA 改用系统证书**(移除内置 ca-bundle.crt)
- User-Agent `ws-http/1.0` → `ws-http/2.0`
- **移除 `ARequest` 静态门面**(统一实例用法)
- **移除 `Request::create($id)` 命名单例池**(改 `new Request()`)
- 体方法字符串 body 无显式 Content-Type 时自动补 text/plain(cURL 只对 POST 自动补)
- cURL 传输错误抛 `RequestException`(含 errno),4xx/5xx 不抛(由断言/引擎判定)
- 修复 1.x `ARequest::verifyHost` 误调 `verifyPeer`
- 移除 `Request::post` 遗留 `output()` 调试调用
