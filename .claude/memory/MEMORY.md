# ws-http 项目经验总结

> 面向后续 Claude Code 会话。先读 `design/README.md`,再按需查阅细化设计文档。

## 环境与工具

- **PHP 7.4 可执行文件**: `/usr/local/bin/php74`(PHP 7.4.32 CLI)
  - 语法检查: `/usr/local/bin/php74 -l <file>`
- **Composer**: `/Users/xuyimu/bin/composer.phar`,用 `php74 /Users/xuyimu/bin/composer.phar <cmd>` 执行
- **PHPUnit**: 无全局命令,作为项目 dev 依赖经 composer 安装,统一用 `vendor/bin/phpunit` 调用(符合"项目级工具不入全局"的原则)
- 系统默认 `php` 不是 7.4,任何验证必须用 `/usr/local/bin/php74`
- composer 操作完成后如有 `vendor/bin/` 下的工具,同样用 `php74 vendor/bin/phpunit` 方式调用

## 项目定位与三层架构

ws-http 是 cURL HTTP 客户端库,重构目标架构(见 design/10):

- **core** (`Ws\Http`): Request/RequestOptions/HeaderBag/Response/Body/Method + `Contract\` 扩展契约接口
- **functional** (`Ws\Http\Assert|Expression|Automated`): 断言器/JSONPath 表达式引擎/自动化引擎(Runner、变量、提取、报告)
- **plugin** (`Ws\Http\Plugin\*`): 三方适配(OpenAI/WordPress 内建样例)+ PluginRegistry 注册机制,同包分发不拆 composer 包

## 关键设计决策(勿反复)

1. **抛弃 v1 历史包袱**:旧代码仅作行为参考,不做 v1 脚本迁移,不保证旧 API 兼容(ARequest 静态门面移除);**代码目录结构允许调整**
2. **零外部依赖**:require 仅 ext-curl/ext-json,不引入 Guzzle、不引入 jsonpath 库(自己实现表达式引擎)
3. **Schema v2**:场景 JSON 全新契约,严格校验拒绝未知字段(v1 header/headers 混拼是教训)
4. **设计总则**:"Simple things should be simple, complex things should be possible" —— 约束复杂度曲线而非功能取舍;功能该实现就实现,分级只决定呈现位置;每份设计文档开头必须有简单路径示例
5. **引擎内部收集结果不抛异常**(除解析失败);异常只用于终止边界
6. **工作流**:先写方案步骤文档 → 按文档逐步实现 → 每步带测试用例

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

## 用户偏好

- 中文交流,文档中文为主、代码标识符英文
- 重视分层架构与可扩展性,反对以"精简"为名砍功能
- 希望先想清楚再动手,不要反复被纠正
- 项目级工具(phpunit 等)装项目里,不用全局命令
- 重要经验/约定写入 `.claude/memory/` 供跨会话使用
