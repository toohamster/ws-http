# ws-http 项目经验总结

> 面向后续 Claude Code 会话。先读 `design/README.md`,再按需查阅细化设计文档。
> 记忆文件按类型拆分:本文件只做索引与状态;细节见各专题文件。

## 专题记忆文件索引

| 文件 | 内容 |
| --- | --- |
| [php74-syntax.md](php74-syntax.md) | PHP 7.4 语法特性矩阵(php74 -l 实测):可用/不可用/陷阱,写代码前必查 |
| [debugging.md](debugging.md) | 调试根因案例:parse_url 字节安全、http_build_query 编码、json errno、方法论 |

## 环境与工具

- **PHP 7.4 可执行文件**: `/usr/local/bin/php74`(PHP 7.4.32 CLI)
  - 语法检查: `/usr/local/bin/php74 -l <file>`
- **Composer**: `/Users/xuyimu/bin/composer.phar`,用 `php74 /Users/xuyimu/bin/composer.phar <cmd>` 执行
- **PHPUnit 9.6.36** 项目 dev 依赖,命令: `/usr/local/bin/php74 vendor/bin/phpunit`
- 系统默认 `php` 不是 7.4,任何验证必须用 `/usr/local/bin/php74`

## 项目定位与三层架构

ws-http 是 cURL HTTP 客户端库,重构目标架构(见 design/10):

- **core** (`Ws\Http`): Request/RequestOptions/HeaderBag/Response/Body/Method + `Contract\` 扩展契约接口
- **functional** (`Ws\Http\Assert|Expression|Automated`): 断言器/JSONPath 表达式引擎/自动化引擎(Runner、变量、提取、报告)
- **plugin** (`Ws\Http\Plugin\*`): 三方适配(OpenAI/WordPress 内建样例)+ PluginRegistry 注册机制,同包分发不拆 composer 包

## 关键设计决策(勿反复)

1. **抛弃 v1 历史包袱**:旧代码仅作行为参考(已移至 legacy/),不做 v1 脚本迁移,不保证旧 API 兼容(ARequest 静态门面移除);**代码目录结构允许调整**
2. **零外部依赖**:require 仅 ext-curl/ext-json;URL 编码/解码用 PHP 内置函数,不自实现字节级处理
3. **Schema v2**:场景 JSON 全新契约,严格校验拒绝未知字段(v1 header/headers 混拼是教训)
4. **设计总则**:"Simple things should be simple, complex things should be possible" —— 约束复杂度曲线而非功能取舍;功能该实现就实现,分级只决定呈现位置;每份设计文档开头必须有简单路径示例
5. **引擎内部收集结果不抛异常**(除解析失败);异常只用于终止边界
6. **工作流**:先写方案步骤文档(design/20)→ 按文档逐步实现 → 每步测试先行;测试失败先批量推演根因再动手(见 debugging.md 方法论)

## 旧代码已知 Bug(重构必须修复)

- `Watcher::assertBody`: strpos($asserted, $body) 参数反 (旧 Watcher.php:118)
- `ARequest::verifyHost` 误调 verifyPeer (ARequest.php:38)
- Automated auth/proxy 条件反转 `empty($x['type'])` (Task/Request.php:173,228)
- Bootstrap::runTask 循环 break 只执行第一个请求 (Bootstrap.php:52)
- `Request::post` 遗留 `output()` 调试调用;`Response` 头大小写敏感;旧自动化 JSON 中 `header`/`headers`/`postProcessors`/`processors` 字段拼写混乱

## 设计文档地图

| 文档 | 内容 |
| --- | --- |
| design/01–03 | 现状分析、需求基线、初版迁移方案(背景) |
| design/10 | 总纲:三层架构、使用者画像、异常体系、错误码段(1xx传输/2xx表达式/3xx断言/4xx引擎/5xx plugin) |
| design/11 | core HTTP 客户端 API 规格(RequestOptions 为 wither 风格) |
| design/12 | 断言器:Watcher 流式 + AssertionRunner 收集式;操作符注册表 |
| design/13 | JSONPath 子集:EBNF 文法、宽容求值(结构不匹配→空集不抛) |
| design/14 | Schema v2:字段契约、校验规则 V1–V10、v1→v2 对照表 |
| design/15 | 变量系统:VariableScope(undefined≠null≠空串)、${var} 深替换、extract onMissing 策略 |
| design/16 | Runner 状态机(failFast/skipped)、Report JSON 契约、CLI(退出码 0/1/2) |
| design/17 | plugin:三件套(认证+端点+语义化方法)、4 类型注册机制、内建样例 |
| design/20 | 实施总计划:S1–S11 步骤与 Done 标准 |

## 项目当前状态(S11 已完成,2.0.0-beta.1 发布,2026-09-16)

- **S1–S11 全部完成,2.0 重构收官**:tag `v2.0.0-beta.1`(commit 2138ff4);alpha.0-core→beta.1 共 10 个 tag
- 质量门禁:PHPStan level 5 **0 error**(phpstan.neon;唯一 ignore=curl_getinfo,系 PHPStan 2.2 存根对 7.4 resource 误报,升级 PHP 8 可移除);Unit 288 + Engine 16 + Integration 10 全绿;composer validate OK
- S11 清理:legacy/ 已删(9.5k 行,git 历史可回溯);README 重写(三层能力表/分层快速开始/扩展机制/迁移差异表);CHANGELOG.md(完整 alpha 链 + B1–B11 修复记录)
- 最终结构:src/Ws/Http(core+Contract)| Expression|Assert|Automated(functional)| Plugin(plugin);bin/ws-http;tests/{Unit,Engine,Integration,fixtures};examples/;design/01–20
- 工具链:phpstan.neon 已配置(treatPhpDocTypesAsCertain: false);光标型 peek/atEnd 函数需 `@phpstan-impure`(副作用读取,消"always true"误报的正确姿势)
- **方法论教训(用户两次强调)**:收尾/排查类工作必须先出完整步骤方案(编号+动作+验证一次列全)再一次性执行;严禁"看一个错改一处重跑"打转;诊断输出一次拿全不截断
- **PHP 8 迭代预留**(design/20 §4,未排期):PHPStan L6、移除 curl_getinfo ignore、readonly/match/enum 等 7.4 降级还原、composer php ^8.1 —— 用户拍板与 PHP 8 升级合并推进,2.0.0-beta.1 为当前交付点
- **所有 PHP 执行必须用 `/usr/local/bin/php74`**(用户强调,最高优先级):phpunit/composer/语法检查/-r 一律 php74 前缀;系统默认 php 是 7.1,禁止直接使用

## 用户偏好

- **强制执行流程(最高优先级,不可跳过)**:理解需求 → 给出方案 → 等用户确认 → 再执行。方案未确认前禁止任何执行动作(包括探测性 API 调用/写文件/跑命令)
- **每次执行工具前自检**:问一句"这符合批量归因规则和强制确认流程吗?"——不符合就不执行
- **失败即批量归因(用户多次强调,必须遵守)**:测试失败后禁止"改一行跑一次"。流程 = 读全量失败输出 + 整文件读相关源码 → 一次列出全部失败的归因清单 → 按清单一次性修改 → 跑**一次全量**验证;探索性诊断脚本最多一次且必须同时覆盖所有假设;读代码整文件读,禁止碎片化 offset 探查
- **git 标签规范(用户指定,2026-09-22 修订,覆盖旧表述)**:
  1. **正式发布标签 = 纯 semver,禁止任何后缀**(`v2.4.0`);短横线后缀会被 GitHub/Composer 解析为 **prerelease**——正式功能发布若带描述后缀(如 `v2.4.0-network-tools`)会被所有标准工具当成不稳定版,永远到不了正式发布位,**这是错误做法**;
  2. **预发布后缀仅在真预发布时使用**,标识用标准词:`v2.4.0-beta.1`/`v2.4.0-rc.1`/`v2.0.0-alpha.0`;
  3. **功能描述不进版本号**——写进 commit message / Release notes / CHANGELOG;
  4. 版本位语义:新功能 = MINOR+1,bug 修复 = PATCH+1,破坏性 = MAJOR+1;
  5. 历史遗留的带描述后缀标签(`v2.1.0-pause` 等 7 个)为已知债务,待用户决定是否清理;
  6. 打标签前必须对照本条自检(本次 `v2.3.5-beta.1`/`v2.3.6-beta.1` 双错:PATCH 位放功能 + 无预发布语义却带 beta 后缀)。
- **执行纪律(2026-09-22 严重违规教训)**:用户对方案的纠正(如标签命名)= **新方案待确认**,正确动作是"理解 → 修正方案 → 等确认";绝不允许在确认前执行任何命令,尤其 **git tag --delete 这类不可逆操作**。被拒绝后应立即停下复述规则,而不是换一条命令继续
- **git add 必须用 `git add .`(用户多次强调,2026-09-22 再次)**:禁止挑文件指定路径 add——容易漏提交(本次 HttpGetTool 删除未 staged 导致 commit 失败即教训);commit 前用 `git status` 检查,敏感文件靠 .gitignore 兜底
- 中文交流,文档中文为主、代码标识符英文
- 重视分层架构与可扩展性,反对以"精简"为名砍功能
- 先想清楚再动手:测试失败先批量推演根因(不逐个试错);设计偏离先确认
- 项目级工具(phpunit 等)装项目里,不用全局命令
- 记忆文件按类型拆分存储(语法/调试/状态分开),不要都塞一个文件
- 重要经验/约定写入 `.claude/memory/` 供跨会话使用
