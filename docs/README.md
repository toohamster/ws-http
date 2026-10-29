# ws-http Documentation

> **Division of labor:** `docs/reference/` is the bilingual **user manual** (module-by-module API & usage) · root-level guides in this directory are the Chinese **mechanics wiki** (how the system works inside) · [`design/`](../design/README.md) is the Chinese **decision archive** (why it is designed this way — developer-facing, not translated).

## Reference — User Manual (bilingual)

Each page is an `[English]` / `[中文版本]` pair (file naming in [CONVENTIONS.md](CONVENTIONS.md)).

### Core — HTTP client

| # | Module | English | 中文 |
| --- | --- | --- | --- |
| 1 | HTTP client (`Request` / `Response` / `Body`) | [core-http-client.md](reference/core-http-client.md) | [core-http-client.zh-CN.md](reference/core-http-client.zh-CN.md) |
| 2 | Configuration reference (`RequestOptions`) | [core-configuration.md](reference/core-configuration.md) | [core-configuration.zh-CN.md](reference/core-configuration.zh-CN.md) |

### Functional — testing engine

| # | Module | English | 中文 |
| --- | --- | --- | --- |
| 3 | Assertion layer (`Watcher`) | [assertion.md](reference/assertion.md) | [assertion.zh-CN.md](reference/assertion.zh-CN.md) |
| 4 | Expression engine (JSONPath subset) | [expression-engine.md](reference/expression-engine.md) | [expression-engine.zh-CN.md](reference/expression-engine.zh-CN.md) |
| 5 | Scenario testing (`Runner` / CLI) | [scenario-testing.md](reference/scenario-testing.md) | [scenario-testing.zh-CN.md](reference/scenario-testing.zh-CN.md) |
| 6 | Variables & extraction | [variables-and-extraction.md](reference/variables-and-extraction.md) | [variables-and-extraction.zh-CN.md](reference/variables-and-extraction.zh-CN.md) |
| 7 | Cookies & external credentials | [cookies-and-auth.md](reference/cookies-and-auth.md) | [cookies-and-auth.zh-CN.md](reference/cookies-and-auth.zh-CN.md) |
| 8 | Data-driven testing (datasets) | [data-driven-testing.md](reference/data-driven-testing.md) | [data-driven-testing.zh-CN.md](reference/data-driven-testing.zh-CN.md) |
| 9 | Scenario as data function (outputs) | [scenario-outputs.md](reference/scenario-outputs.md) | [scenario-outputs.zh-CN.md](reference/scenario-outputs.zh-CN.md) |

### NanoGpt — agent orchestration

| # | Module | English | 中文 |
| --- | --- | --- | --- |
| 10 | Agent core (loop / tools / sandbox) | [agent-core.md](reference/agent-core.md) | [agent-core.zh-CN.md](reference/agent-core.zh-CN.md) |
| 11 | Transports (multi-provider) | [agent-transports.md](reference/agent-transports.md) | [agent-transports.zh-CN.md](reference/agent-transports.zh-CN.md) |
| 12 | Session, memory & skills | [agent-session.md](reference/agent-session.md) | [agent-session.zh-CN.md](reference/agent-session.zh-CN.md) |
| 13 | cc-gpt shell (reference CLI) | [agent-ccgpt-shell.md](reference/agent-ccgpt-shell.md) | [agent-ccgpt-shell.zh-CN.md](reference/agent-ccgpt-shell.zh-CN.md) |

### Reference — cross-module

| # | Module | English | 中文 |
| --- | --- | --- | --- |
| 14 | Error codes (1xx–6xx) | [error-codes.md](reference/error-codes.md) | [error-codes.zh-CN.md](reference/error-codes.zh-CN.md) |
| 15 | Extension points (registries) | [extension-points.md](reference/extension-points.md) | [extension-points.zh-CN.md](reference/extension-points.zh-CN.md) |
| 16 | CHANGELOG guide | [CHANGELOG](../CHANGELOG.md) | —(版本历史,双语共用) |

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
