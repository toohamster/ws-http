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

## 项目当前状态(S4 已完成,2026-09-11)

- **S1–S4 完成**:Unit 82 tests 192 assertions 全绿;`php74 vendor/bin/phpunit --testsuite Unit`
- S2 交付:Exception、RequestException(访问器风格)、Method(+NO_BODY_METHODS)+ MethodHelper、HeaderBag
- S3 交付:RequestOptions(wither 不可变;timeout 默认 30;mutate() 克隆 HeaderBag)
- S4 交付:Response(statusLine/jsonError/isOk/totalTime)、PreparedBody、Body(json/form/multipart/file/raw)、UrlKit(buildHttpQuery 拍平 + encodeUrl 预编码方案,替代旧 bin2hex 技巧)
- 约定:不可变 = private typed property + 访问器 + @immutable 注释;原始大小写保留"首次出现"者;接口不能有方法体(静态逻辑放 Helper 类)

## 用户偏好

- 中文交流,文档中文为主、代码标识符英文
- 重视分层架构与可扩展性,反对以"精简"为名砍功能
- 先想清楚再动手:测试失败先批量推演根因(不逐个试错);设计偏离先确认
- 项目级工具(phpunit 等)装项目里,不用全局命令
- 记忆文件按类型拆分存储(语法/调试/状态分开),不要都塞一个文件
- 重要经验/约定写入 `.claude/memory/` 供跨会话使用
