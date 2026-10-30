# ws-http Documentation

> **Division of labor:** `docs/reference/` is the **user manual** (module-by-module API & usage; English default + `reference/zh-CN/` mirror, one directory per language) · root-level guides in this directory are the Chinese **mechanics wiki** (how the system works inside) · [`design/`](../design/README.md) is the Chinese **decision archive** (why it is designed this way — developer-facing, not translated).

## Reference — User Manual (English)

| # | Module | Page |
| --- | --- | --- |
| 1 | HTTP client (`Request` / `Response` / `Body`) | [core-http-client.md](reference/core-http-client.md) |
| 2 | Configuration reference (`RequestOptions`) | [core-configuration.md](reference/core-configuration.md) |
| 3 | Assertion layer (`Watcher`) | [assertion.md](reference/assertion.md) |
| 4 | Expression engine (JSONPath subset) | [expression-engine.md](reference/expression-engine.md) |
| 5 | Scenario testing (`Runner` / CLI) | [scenario-testing.md](reference/scenario-testing.md) |
| 6 | Variables & extraction | [variables-and-extraction.md](reference/variables-and-extraction.md) |
| 7 | Cookies & external credentials | [cookies-and-auth.md](reference/cookies-and-auth.md) |
| 8 | Data-driven testing (datasets) | [data-driven-testing.md](reference/data-driven-testing.md) |
| 9 | Scenario as data function (outputs) | [scenario-outputs.md](reference/scenario-outputs.md) |
| 9b | Plugin system | [plugin-system.md](reference/plugin-system.md) |
| 10 | Agent core (loop / tools / sandbox) | [agent-core.md](reference/agent-core.md) |
| 11 | Transports (multi-provider) | [agent-transports.md](reference/agent-transports.md) |
| 12 | Session, memory & skills | [agent-session.md](reference/agent-session.md) |
| 13 | cc-gpt shell (reference CLI) | [agent-ccgpt-shell.md](reference/agent-ccgpt-shell.md) |
| 14 | Error codes (1xx–6xx) | [error-codes.md](reference/error-codes.md) |
| 15 | Extension points (registries) | [extension-points.md](reference/extension-points.md) |
| 16 | CHANGELOG guide | [CHANGELOG](../CHANGELOG.md) |

## Reference — 用户手册(简体中文)

| # | 模块 | 页面 |
| --- | --- | --- |
| 1 | HTTP 客户端(Request / Response / Body) | [core-http-client](reference/zh-CN/core-http-client.zh-CN.md) |
| 2 | 配置参考(RequestOptions) | [core-configuration](reference/zh-CN/core-configuration.zh-CN.md) |
| 3 | 断言层(Watcher) | [assertion](reference/zh-CN/assertion.zh-CN.md) |
| 4 | 表达式引擎(JSONPath 子集) | [expression-engine](reference/zh-CN/expression-engine.zh-CN.md) |
| 5 | 场景测试引擎(Runner / CLI) | [scenario-testing](reference/zh-CN/scenario-testing.zh-CN.md) |
| 6 | 变量与提取 | [variables-and-extraction](reference/zh-CN/variables-and-extraction.zh-CN.md) |
| 7 | Cookie 与外部凭据注入 | [cookies-and-auth](reference/zh-CN/cookies-and-auth.zh-CN.md) |
| 8 | 数据驱动测试(数据集) | [data-driven-testing](reference/zh-CN/data-driven-testing.zh-CN.md) |
| 9 | 场景作为取数函数(outputs) | [scenario-outputs](reference/zh-CN/scenario-outputs.zh-CN.md) |
| 9b | 插件系统 | [plugin-system](reference/zh-CN/plugin-system.zh-CN.md) |
| 10 | Agent 核心(循环 / 工具 / 沙箱) | [agent-core](reference/zh-CN/agent-core.zh-CN.md) |
| 11 | 传输层(多厂商) | [agent-transports](reference/zh-CN/agent-transports.zh-CN.md) |
| 12 | 会话、记忆与技能 | [agent-session](reference/zh-CN/agent-session.zh-CN.md) |
| 13 | cc-gpt 壳(参考 CLI) | [agent-ccgpt-shell](reference/zh-CN/agent-ccgpt-shell.zh-CN.md) |
| 14 | 错误码(1xx–6xx) | [error-codes](reference/zh-CN/error-codes.zh-CN.md) |
| 15 | 扩展点(注册表) | [extension-points](reference/zh-CN/extension-points.zh-CN.md) |
| 16 | 版本历史 | [CHANGELOG](../CHANGELOG.md)(双语共用) |

## Guides — Mechanics Wiki (中文)

One page per mechanism: principles, sequences, real code, common misconceptions.

| 主题 | 文件 | 关联设计 |
| --- | --- | --- |
| 模型怎么知道调用工具(工具调用机制) | [nanogpt-tool-invocation.md](nanogpt-tool-invocation.md) | design/21 §3, design/25 |

### 待增补主题(按需)

- 为什么 rm 不在 exec 白名单里,而要走 delete_file(防线跟威胁面走)
- 场景怎么变成"取数函数"(outputs 契约)→ reference #9 落地后可引用其实现
- 上下文压缩的触发与代价(compactor 是一次 LLM 调用)

## For developers

Design decisions, contracts, and the implementation progress ledger live in [`design/`](../design/README.md) (Chinese, developer-facing; entry point: [design/PROGRESS.md](../design/PROGRESS.md)).
