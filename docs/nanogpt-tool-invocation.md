# 模型怎么知道调用工具(NanoGpt 工具调用机制)

> **读者**:NanoGpt 使用者、工具作者、想理解 agent loop 的开发者。
> **你将理解**:为什么 Agent 从不"决定"调用工具;一次工具调用的完整旅程;以及——最重要的——**这个机制如何指导你写出会被正确调用的工具**。
> 关联:design/21 §3(Agent loop 状态机)、design/25(工具谱系与说明书质量)。

## 一句话结论

**"什么时候调用工具"不是 Agent 决定的,是模型决定的**——Agent 从不分析你的输入、不匹配关键词、不做任何"该用哪个工具"的判断;它只是把工具的**说明书**随每次请求交给模型,然后等模型在响应里说"我要用某个工具"。Agent 负责的只有**执行和回传**。这是一个被刻意设计出来的分工。

## 完整旅程:一句"看看 README 写了什么"的六站

```
你: "看看 README 写了什么"
│
├─ ① 请求(第 1 轮)
│     发给模型: [对话历史] + [工具目录(tools 参数)]
│
├─ ② 模型决策(黑盒发生在这里)
│     模型读对话 + 读说明书 → 语义判断:回答它需要读文件能力
│     返回: tool_calls: [{ name: "read_file", arguments: '{"path":"README.md"}' }]
│     ↑ 注意:连参数值都是模型"填"的(从对话上下文推断)
│
├─ ③ Agent 解析 + 执行(纯机械,无智能)
│     ToolRegistry::get("read_file") → execute({path:"README.md"})
│     → 安全防线全部在这一步内部(Sandbox / 白名单 / 元字符拒绝)
│     → 得到文件内容
│
├─ ④ 回填(关键动作)
│     对话历史追加一条特殊消息:
│     { role: "tool", tool_call_id: "call-1", content: "...README 内容..." }
│
├─ ⑤ 请求(第 2 轮)—— 把完整对话再发给模型
│     模型看到自己上轮要的工具结果 → 继续推理:
│     可能再调工具(循环,受 maxTurns=8 约束),或生成最终文本
│
└─ ⑥ 你看到: "README 介绍了 ws-http 是一个……"   ← 循环结束
```

## 模型是怎么"决定"的

OpenAI function calling 协议下,模型收到两类输入:

1. **对话历史**(messages)——它要回答什么;
2. **工具目录**(tools 参数)——每个工具的 `name` / `description` / `parameters` JSON Schema。

模型在**生成回复的那一刻**判断:回答这个问题是否需要外部能力?这是它预训练+微调习得的能力(训练时见过海量"何时该调工具"的样本)。判断完全靠**语义匹配**:

```
"帮我看看 README 里写了什么"   → 语义含"读文件"     → 选 read_file
"你好"                        → 不需要外部能力      → 直接文本回复(tool_calls 为空)
"北京今天多少度"               → 自己无实时数据      → 有天气工具就调,没有就坦白说不知道
```

三种结果对应 `Agent.php` 的响应三态:有 `tool_calls` → 进工具循环;有 `content` → 输出文本结束;`finish_reason=length` → 提示截断。

## 对使用者的三个实际影响(这个机制直接改变你的行为)

### 1. 模型不调用你的工具?——先查说明书,别查代码

模型选不选工具,几乎完全取决于 `description()` 和参数 schema 写得好不好。反面与正面:

```
✗ description: "文件操作"                  ← 模型不知道何时该用它
✓ description: "Read the content of a file inside the sandbox
   workspace. Returns the file content as text."   ← 明确能力边界+返回形态
```

自检清单:能力边界清楚吗(做什么/不做什么)?参数含义与格式有示例吗?名字是语义化的吗(`search_text` 优于 `st`)?

### 2. 参数总填错?——schema 是约束也是提示

`parameters` 里的 `required` / `enum` / 描述不只是给运行期校验用的——**模型生成参数时就依赖它们**。`CommandToolBase` 自动生成 schema、槽位带 `description`,就是让"模型填参数"这件事有据可依。

### 3. 工具连调不停?——maxTurns 是循环的刹车

模型每轮都能"反悔"(看到工具结果后决定再调下一个)——这是 agent loop 多步推理的威力,也是死循环风险所在。`Agent` 构造的 `maxTurns`(默认 8)到达后抛 601,而不是无限烧 token。

## 常见误解

| 误解 | 事实 |
| --- | --- |
| "Agent 里有段逻辑在决定用哪个工具" | 没有。Agent 对工具的选择零参与;决策 100% 在模型侧 |
| "模型自己执行了工具" | 不会。模型只会"说要用",执行永远在 Agent 侧的 PHP 代码里——这正是安全防线(Sandbox/白名单)能成立的原因 |
| "工具结果直接返回给用户" | 不会。结果作为 `role: tool` 消息**回填给模型**,由模型组织成最终回复——所以模型可以基于工具结果继续推理甚至再调工具 |
| "调一次工具 = 一轮对话结束" | 工具循环(调→回填→再问)发生在**一次** `Agent::run()` 内;对使用者表现为一次输入,内部可能是 N 次模型请求 |

## 实现代码索引

| 环节 | 位置 |
| --- | --- |
| 工具目录汇编(jsonSchemas) | `src/Ws/Http/NanoGpt/ToolRegistry.php` |
| 请求携带 tools / 响应三态分发 | `src/Ws/Http/NanoGpt/Agent.php`(run 方法) |
| tool_calls 解析→执行→回填 | `Agent.php`(循环体内) |
| 说明书自动生成 | `src/Ws/Http/NanoGpt/Tool/CommandToolBase.php`(jsonSchema) |

## 延伸阅读

- 为什么工具分谱系、防线跟执行体走:design/25(工具谱系)
- Agent loop 的完整状态机与错误码:design/21 §3/§7
- 想亲手写一个会被正确调用的工具:examples/cc-gpt/README「扩展指南」
