[English](./CONVENTIONS.md) | [中文版本](./CONVENTIONS.zh-CN.md)

# Documentation Conventions (docs/ 双语文档规范)

> **适用范围**:`docs/reference/`(双语用户手册)与两个根 README 的全部内容。
> **不适用**:`design/`(开发者向决策档案——按设计保持中文,永不翻译)与根目录运营文件(`CHANGELOG.md`、`CLAUDE.md`)。
> 下述语言切换行格式、文件配对、节模板与术语表,对 2026-10-28 之后生成的每份文档**具有约束力**。

---

## 1. 受众图谱(谁读什么)

| 读者 | 阅读 | 语言 |
| --- | --- | --- |
| 工具使用者(GitHub / Packagist 访客) | `README.md` + `docs/reference/*` | 英文默认,中文可切换 |
| 探究机制的工具使用者 | `docs/*` guides(wiki) | 中文(后续可双语,暂不强制) |
| 项目开发者(设计溯源) | `design/*` + `design/PROGRESS.md` | 中文(保持原样,不翻译) |

**分工(各一句话):**

- `README` — 项目是什么、怎么装、第一行代码、导航;
- `docs/reference/` — 手册:逐模块的 API 与用法(双语);
- `docs/` 根级 guides — 系统内部如何运转(叙事 wiki);
- `design/` — 为什么这样设计、契约是什么(决策档案)。

## 2. 文件配对(双语文机制)

1. **命名**:`<module>.md`(英文,默认主文档)+ `<module>.zh-CN.md`(中文对照)。同名配对,一一对应。缺一篇即索引断链——按构建错误对待。
2. **切换行**:每篇配对文档(双语皆然)的**第一行**固定为:

   ```markdown
   [English](./<module>.md) | [中文版本](./<module>.zh-CN.md)
   ```

   两个根 README 用:`[English](README.md) | [中文版本](README.zh-CN.md)`(各自链接对方)。
3. **段落对齐**:中文镜像逐节跟随英文文档——同顺序、同节标题数。一侧增节,必须同一次变更补进另一侧(一内容单元一提交——语言不得漂移)。
4. **不翻译的内容**:
   - 代码块(双语完全一致;仅代码内注释可译);
   - 类 / 方法 / 命名空间名、JSON 键、文件名、错误码、CLI 参数;
   - 链接目标路径(两个版本链接同一批真实目标)。
5. **冲突裁决**:英文版为准,中文镜像跟随修正。先改英文,再同步镜像。

## 3. 节模板(每篇手册页,双语一致)

每篇模块文档包含且仅包含以下五个顶层节,顺序固定:

```markdown
## Overview                 — 模块职责与设计意图(2–5 句)
## Key Components           — 类/接口与真实签名(PHP 7.4 类型)
## Usage Examples           — ≥ 2 个来自真实 API 的可运行片段(严禁编造)
## Configuration            — 配置键/默认值;无配置则一行说明原因
## Dependencies             — 依赖/被依赖,相关页与 design 文档链接
```

补充规则:

- 签名从 `src/` 拷贝(经 grep 实测),以 ```php 围栏 + doc-comment 摘要呈现;
- 每个示例必须能从所展示签名复现——严禁 `new HttpClient()` 式幻觉(真实入口是 `new Ws\Http\Request()`);
- 含注释的 JSON 示例用 `jsonc` 围栏;
- 跨页链接用**相对路径**;design 文档链接(文件名含空格)必须把空格 URL 编码为 `%20`;
- 每篇末尾以 "Design details" 行链接权威 `design/` 文档。

## 4. 术语表(中文镜像的硬约束)

| English | 中文(固定) | 备注 |
| --- | --- | --- |
| Scenario | 场景 | 不用 剧本/脚本流程 |
| Assertion | 断言 | |
| Assertion Operator | 断言操作符 | |
| Extraction / Extract | 提取 | |
| Variable Scope | 变量作用域 | |
| Scenario Runner | 场景执行器 | |
| Step | 步骤 | |
| Report | 报告 | |
| Batch / Record | 批次 / 记录 | 数据驱动语境 |
| Cookie Store | Cookie 存储 | |
| Value Source | 取值来源 | |
| Pause Step | 暂停步骤 | |
| Plugin | 插件 | |
| Auth Provider | 认证提供器 | |
| Endpoint | 端点 | |
| Tool | 工具 | |
| Tool Registry | 工具注册表 | |
| Sandbox | 沙箱 | |
| Agent Loop | Agent 循环 | Agent 不译 |
| Event Stream | 事件流 | |
| Transport | 传输 | 厂商接入层语境 |
| Vendor Class | 厂商类 | |
| Capability Profile | 能力档案 | |
| Retry / Backoff | 重试 / 退避 | |
| Compaction / Compactor | 压缩 / 压缩器 | |
| Context Budget | 上下文预算 | |
| Snapshot / Restore | 快照 / 恢复 | |
| Long-term Memory | 长期记忆 | |
| Skill | 技能 | |
| Descriptor | 描述文件 | .tool.json 语境 |
| Whitelist | 白名单 | |
| Secret / Redaction | 敏感值 / 脱敏 | |

**不译清单**:类名(`Request`、`Watcher`…)、方法名(`assertJsonPath`…)、命名空间、HTTP 方法与状态码、cURL 常量、JSON 键、错误码数字、CLI 参数(`--format`)。

## 5. 索引契约(docs/README)

- `docs/README.md` 是 **docs/ 的唯一索引**:Reference 区(16 篇手册 × 2 语言并列)与 Guides 区(机制 wiki)分区列出;
- 根 README 双语版各含一条 Documentation 导航,指向 `docs/README.md`;
- `design/` 只被**单向引用**(docs/design 细节链接),不出现在索引里(它是开发者档案)。

## 6. 变更协议

- **一内容单元一提交**:新增/修改一篇手册 = 英文 + 中文镜像 + 索引行,**同一提交**(碎片提交禁令与提交粒度协议同构);
- 结构性变更(增删五节模板、术语表改动)= 先改本规范并单独立项确认,再回溯已有文档;
- 链接完整性:提交前校验所有相对链接目标存在(含 `%20` 编码)。
