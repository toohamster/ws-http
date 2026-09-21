# 26 Skill(可分享的任务级能力包:声明式定义 → /skill 命令装配)

> 层归属:**壳段(`CcGpt\`)为主,装载基建与 design/25 §4 同源**(组件段的 Loader 扩展一种 schema)。
> 状态:**仅落需求,实现等后续确认**(依赖 design/25 层 2 描述文件装载先落地)。
> 背景:工具三层分类评审(2026-09-21)——层 1 代码 CommandTool(design/25 实现)/ 层 2 声明式描述文件(design/25 实现)/ **层 3 Skill(本设计)**。层 3 与层 2 的差异:粒度从"单命令"升到"任务级能力包"(指令模板 + 多工具编排),交互形态为 `/skill name args`。

**简单路径**(使用者视角):

```
you> /skill json-report data.json
(skills/json-report.skill.json 的指令模板渲染后作为本轮 user 消息发出)
⚙ read_file {"path":"data.json"}
⚙ exec ... (分析脚本)
你需要的报告如下:...
(之后会话里它就是一条普通 user 消息——持久化/压缩/rewind 全部自动兼容)
```

> 简单路径使用者只需要:把 skill 文件放进 skills/ 目录,/skill 列表选择执行。装载/校验/注入机制见 §2–§3。

## 1. 概念与边界

**Skill 是什么**:

1. **一个声明文件**(`skills/*.skill.json`):name + description + **指令模板**(自然语言,给模型的任务说明)+ 参数槽(`{arg}` 占位,用户经 /skill 参数替换)+ 可选 allowed-tools(约束本 skill 执行期间可用的工具);
2. **一个壳命令**(`/skill`):无参列出可用 skill;`/skill name args...` 装载定义 → 渲染指令模板 → 作为**本轮 user 消息**发出 → Agent 正常 run;
3. **与层 2 同一装载基建**:扫描/校验/注册骨架与 design/25 §4 的 DescriptorToolLoader 同源,仅 schema 不同(command 槽 → instruction 模板)。

**Skill 不是**:

- 不是新引擎:执行层零新东西——skill 最终就是"一条(可能很长的)user 消息 + 已注册工具",Agent loop / 会话机制(持久化/压缩/rewind)**零特殊处理**(skill 调用在历史里是普通 user 消息,自动兼容);
- 不是提示词持久化:skill 指令**不进 system**(见 §3 注入位决策),只在被调用的那轮作为 user 消息进入会话;
- 不是 Tool Store:发布/分享 = 复制一个 skill.json 文件(与层 2 同哲学,无网络/签名/版本化);
- 不是自动编排:多工具协作由模型按指令模板自行驱动(已有 agent loop 能力),skill 不定义固定执行步骤图。

## 2. 描述文件格式(skills/*.skill.json)

```jsonc
{
  "name": "json-report",                       // 必填,唯一(与 command 工具名共享命名空间?否——独立空间,前缀区分)
  "description": "Analyze a JSON file and produce a structured report",
  "instruction": "分析 {path} 这个 JSON 文件:先 read_file 读取,统计顶层键结构与记录数,然后输出一份包含字段类型分布、空值率、样本值(截断 200 字符)的报告。不要修改任何文件。",   // 必填:给模型的指令模板,{slot} 为参数槽
  "arguments": {
    "path": { "type": "string", "description": "JSON file path within sandbox", "required": true }
  },
  "allowedTools": ["read_file", "list_dir", "exec"]   // 可选:缺省 = 不限制(全部已注册工具)
}
```

校验规则(V 序列属壳层,编号 S1–S4 避免与场景 V 混淆):

- **S1**:name 必填且匹配 `^[a-z][a-z0-9-]*$`;skill 名之间唯一(装载期冲突 → 提示文件名);
- **S2**:instruction 必填非空;`{slot}` 占位必须在 arguments 中有定义(防模板漏参);arguments 中未被模板引用的槽提示警告(不拒绝);
- **S3**:arguments 槽 schema 与 design/25 §3 同构(type/description/required/default,不支持嵌套);
- **S4**:allowedTools 若存在,必须是数组且**装载期不校验工具名存在性**(工具可能后注册;执行期校验,未知工具名在渲染时忽略并提示)。

## 3. 注入位决策(评审钉死:user 消息渲染,非 system 叠加)

| | **a. user 消息渲染(选定)** | b. system 叠加(否决) |
| --- | --- | --- |
| 机制 | /skill 渲染指令模板 → 本轮 user 消息 | setSystem 追加 skill 指令 |
| 作用域 | 该轮起随会话历史自然留存 | 全局(换话题 skill 还在,污染) |
| 与 Memory 冲突 | 无(Memory 在 system) | 注入位冲突(两个来源改 system) |
| 会话机制兼容 | 零特殊处理(就是普通 user 消息) | 压缩/rewind 需额外考虑 system 变更 |
| 上下文成本 | 指令每轮都在历史里(超长 skill 由 /compact 自然处理) | 不占 user 位 |

- 渲染规则:`{slot}` 替换(未提供的 required 槽 → 提示补参,不发请求);渲染后文本前缀 `[skill: json-report] `(会话历史中可辨识,且 auto-compact 摘要时模型能理解这是技能调用);
- allowedTools 的执行期约束实现:**渲染进指令文本**("本次任务只允许使用:...")——不做运行期工具注册表裁剪(那是 Agent 状态变更,越界);约束是提示性的,与"结构安全靠 CommandTool 骨架"分层一致:危险操作本就被工具层挡住,allowedTools 只是任务聚焦。

## 4. 壳层:/skill 命令(CcGpt\Command\Skill)

| 调用 | 行为 |
| --- | --- |
| `/skill` | 列出 skills(name + description,来自 skills/ 目录扫描) |
| `/skill name args...` | 装载 → S 校验 → 渲染(参数替换)→ 作为 user 消息发 Agent run(走既有 renderEvent 事件流) |
| `/skill name --info` | 显示指令模板原文与参数槽(执行前预览) |

装配:壳 bootstrap 时 `SkillLoader(skills/)`(与 tools/ 装载同基建)→ /skill 命令持有清单;skills/ 目录在壳根(design/21 §8 三区之外的第四区?**否**——skills/ 是**用户资产、随壳入库可分享**,放壳根且**不 gitignore**;与 .work(产出)/.runtime(草稿)生命周期不同)。

## 5. 测试要点

| 用例组 | 覆盖 |
| --- | --- |
| SkillLoader(与 tool loader 同源回归) | 合法装载;S1–S4 各拒绝态;skill 名冲突;*通配 |
| 渲染 | 参数替换/required 缺失提示/{slot} 未定义拒绝(S2)/前缀标记 |
| /skill 命令 | 无参列表;执行走 renderEvent 事件流;--info;未知 skill 名 |
| 会话兼容 | skill 消息在 snapshot/restore 往返中原样;被 /compact 正常摘要;rewind 可回退到 skill 调用前 |
| allowedTools | 装载期不校验存在性;渲染期未知工具名忽略 + 提示 |

## 6. 实施前置与触发条件

- **前置**:design/25 C3(描述文件装载)已落地——SkillLoader 复用其扫描/校验骨架;
- **触发条件**(何时启动实现):层 2 描述文件工具在真实使用中跑通(至少 1 个自建 .tool.json 在日常使用),且出现"固化操作流程"的真实诉求(同一套多步骤说明被反复手打 ≥3 次);
- **实现规模预估**:SkillLoader(~60 行,复用层 2)+ 渲染与 /skill 命令(~80 行)+ 测试——单个提交粒度;
- **独立提交打标签**:`v2.4.x-skill` 序列。

**风险与对策**:① 指令模板过长占上下文 → /compact 兜底(它就是普通消息);② skill 与用户输入混淆 → `[skill: name]` 前缀 + 模型可辨识;③ allowedTools 的提示性约束被模型无视 → 危险操作本被工具层结构性挡住(CommandTool 骨架/文件工具沙箱),skill 层不承担安全兜底职责(分层明确)。
