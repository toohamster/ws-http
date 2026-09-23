# 21 NanoGpt 组件设计(OpenAI tools 协议上的 agent 编排层)

> 层归属:**新子命名空间 `Ws\Http\NanoGpt\`**(与 Plugin 平级,依赖单向:NanoGpt → core/functional/Plugin\OpenAI;core/functional/Plugin 不感知它)。
> 使用者:要在任意项目中复用 OpenAI 协议 agent loop 的开发者;CLI 案例作者(examples/cc-gpt 是它的第一个消费壳)。
> 决策:直接以**组件**形态落位(不先做 example 再搬家);理由见 design/21 评审记录 §二——agent loop + 工具协议 + 沙箱是建立在 OpenAI 协议上的可复用编排层,不属于 core(HTTP)也不满足 plugin 三件套(认证+端点+语义化方法)判据,塞进 plugin 反而破坏"plugin 机制最小化"承诺(design/17 §2)。

**简单路径**(使用者视角,只面对 Agent 与工具注册):

```php
use Ws\Http\NanoGpt\Agent;
use Ws\Http\NanoGpt\Tool\{ReadFileTool, HttpGetTool};

$agent = new Agent(
    new \Ws\Http\Plugin\OpenAI\Client($apiKey, null, 'https://orcarouter.ai/v1'),
    'gpt-4o-mini'
);
$agent->tools()->register(new ReadFileTool($sandbox));
$agent->tools()->register(new HttpGetTool());

foreach ($agent->run('帮我读一下 cc-gpt/README.md 并总结') as $event) {
    echo $event['type'], ': ', $event['text'] ?? '';   // text | tool_call | tool_result | error
}

$agent->conversation()->usage();   // 跨轮累积的 token 用量
```

> 简单路径使用者不需要知道:tool_calls 如何解析、tool 消息如何回填、循环何时终止。这是"复杂的事情必须可能"的部分,见 §3。

## 1. NanoGpt 是什么、不是什么

**是**:

1. **Agent loop 引擎**:`messages + tools → POST /chat/completions → 解析 tool_calls → 执行工具 → role=tool 结果回填 → 循环`的状态机,直至产出文本或达到 maxTurns;
2. **工具协议**:`ToolInterface`(name/description/jsonSchema/execute)+ `ToolRegistry` 注册表 + JSON Schema 汇总,映射到 OpenAI tools 参数;
3. **沙箱**:`Sandbox` 集中实现 cwd 锚定 + `realpath` 前缀穿越校验,内建文件工具统一走它;
4. **会话状态**:`Conversation` 持有 messages[] 与跨轮 usage 累积。

**不是**(防过度设计):

- 不是第二个 OpenAI 客户端:所有 HTTP 调用复用 `Plugin\OpenAI\Client`(chat/models 端点),NanoGpt 不直接发请求、不重复认证逻辑;
- 不是消息对象模型/流式协议:messages 保持纯数组(与 OpenAI wire 格式一致),不做流式(扩展预留);
- 不是 CLI 框架:REPL/命令/UI 全部在 examples/cc-gpt 壳层,组件不依赖任何 I/O;
- 不是 plugin:不走 PluginInterface/PluginRegistry 机制,独立子命名空间,bootDefaults 不涉及它。

## 2. 目录结构与类清单

```
src/Ws/Http/NanoGpt/
├── Agent.php                 # agent loop 状态机(核心)
├── ToolInterface.php         # 工具契约(组件扩展点)
├── ToolRegistry.php          # 注册表:name → ToolInterface
├── Sandbox.php               # 安全边界:root 锚定 + realpath 前缀校验
├── Conversation.php          # 会话状态:messages[] + usage 累积
├── AgentException.php        # 错误码段 600–699(design/10 §5 追加)
└── Tool/                     # 内建工具(全部经 Sandbox 或 core Request)
    ├── ReadFileTool.php
    ├── WriteFileTool.php
    ├── ListDirTool.php
    └── HttpGetTool.php       # GET JSON API,回传摘要(复用 core Request,受白名单约束)
```

## 3. Agent:agent loop 状态机

```php
final class Agent
{
    public const DEFAULT_MAX_TURNS = 8;

    public function __construct(
        \Ws\Http\Plugin\OpenAI\Client $client,   // 唯一 HTTP 依赖
        string $model,                           // 'gpt-4o-mini' 等
        int $maxTurns = self::DEFAULT_MAX_TURNS  // 防死循环
    );

    public function tools(): ToolRegistry;       // 组件扩展点
    public function conversation(): Conversation;
    public function systemPrompt(?string $prompt): void;

    /**
     * 运行一轮 agent loop(用户输入 → 产出文本)。
     * @param string $userInput
     * @return \Generator<int, array<string,mixed>> 事件流:
     *   ['type' => 'text',       'text' => string]                  # 最终 assistant 文本
     *   ['type' => 'tool_call',  'name' => string, 'args' => array] # 工具调用(执行前)
     *   ['type' => 'tool_result','name' => string, 'result' => string]
     *   ['type' => 'error',      'message' => string, 'code' => int]
     * @throws AgentException 601 超过 maxTurns 仍未产出文本 / 602 未知名工具
     */
    public function run(string $userInput): \Generator;
}
```

### 3.1 状态机

```
用户输入(非命令)
 → conversation 追加 role=user
 → POST /chat/completions {model, messages, tools: registry->jsonSchemas()}   # 经 ChatEndpoint::create($messages, $model, ['tools'=>…])
 → 响应三态(读 $response->body->choices[0]->message):
    a) content 非空 → yield text;usage 累积;assistant 消息追加;结束本轮
    b) tool_calls 非空 → assistant 消息(含 tool_calls)追加;逐个:
         registry 查 name → execute(args) → yield tool_call/tool_result
         结果以 ['role'=>'tool','tool_call_id'=>…,'content'=>string] 追加 messages
         → 回到 POST(turn+1;turn > maxTurns → throw 601)
    c) finish_reason=length → yield error(提示截断),保留会话
 → 传输异常/HTTP 4xx:包装 RequestException 为 error 事件,会话保留(可重试)
```

要点:

- **usage 累积**在每次响应成功后进行(`$body->usage`:prompt_tokens/completion_tokens/total_tokens);
- tool 执行**异常不终止 loop**:捕获为字符串错误内容回填(模型可自行纠正),同时 yield error 事件;仅"未知名工具"(602)与超 maxTurns(601)抛 AgentException;
- 不做空响应重试——传输失败由调用方决定重试(会话保留,再调一次 run 即可重试同一轮)。

## 4. 工具协议

```php
namespace Ws\Http\NanoGpt;

interface ToolInterface
{
    public function name(): string;            # 唯一,snake_case:'read_file'
    public function description(): string;     # 给模型看的自然语言描述
    /** OpenAI function JSON Schema(parameters 字段) */
    public function jsonSchema(): array;
    /** 执行;返回给模型看的字符串结果(任何异常应转为错误字符串,见 §3.1) */
    public function execute(array $args): string;
}

final class ToolRegistry
{
    public function register(ToolInterface $tool): void;   # 重名抛 AgentException 603
    public function has(string $name): bool;
    public function get(string $name): ToolInterface;      # 未知名抛 AgentException 602
    /** 汇总为 OpenAI tools 数组:[['type'=>'function','function'=>['name','description','parameters']]] */
    public function jsonSchemas(): array;
}
```

### 4.1 内建工具(全部经 Sandbox,除 HttpGet)

| 工具 | 行为 | 参数 schema |
| --- | --- | --- |
| ReadFileTool | 读文件内容(UTF-8 截断保护) | path: string |
| WriteFileTool | 写文件(父目录不存在自动创建) | path: string, content: string |
| ListDirTool | 列目录(名称+类型,不递归) | path: string |
| HttpGetTool | GET JSON API,回传状态码+body 摘要(截断);**域名白名单**构造时传入,空表 = 禁用 | url: string |

- 文件工具构造时注入 `Sandbox`,只接受 `resolve()` 后的路径——**穿越检查集中在 Sandbox 一处**,工具自身不重复实现;
- HttpGetTool 是"读网络接口"最小形态(不做 POST/认证/分页),复杂 HTTP 需求应由使用者自定义 Tool 实现——这本身就是组件扩展点的演示。

### 4.2 Preset(出厂预设集,装配期概念)

**动机**:裸组件要求使用者自己 register 一串工具(接入样板重复);"某服务没有文件能力需要整体禁用"是真实需求。Preset 把"一组出厂内容"打包成可整体替换的预设——它是**装配期(bootstrap)概念**,与 design/17 plugin(三方系统适配打包)同心智,但作用于"NanoGpt 能力预设"维度。

**边界(防过度设计)**:

1. Preset **不碰 agent loop 语义**:不覆盖 maxTurns/systemPrompt/model(那些是 Agent 构造参数);只管"装什么工具、用什么模型目录";
2. **不做** Package 注册表/发现机制/版本兼容——Preset 就是实现了接口的一个普通类,使用者 new 哪个用哪个,无全局"已安装包"状态;
3. "禁用"语义 = 选 MinimalPreset 或从 tools() 数组移除,**不做** enable/disable 开关矩阵;
4. 命令(/xxx)是壳的概念,组件不感知——命令集由壳按 Preset 装配(§8)。

```php
namespace Ws\Http\NanoGpt;

interface Preset
{
    /** @return ToolInterface[] 出厂工具集(使用者可在此基础上增删) */
    public function tools(): array;

    /** 模型目录来源(§8.1);null = 壳自行处理 */
    public function modelSource(): ?ModelSourceInterface;
}

final class FullPreset implements Preset {}     // 4 内建工具 + OrcaRouterModelSource
final class MinimalPreset implements Preset {}  // 纯对话,零工具(禁用场景一行解决)
```

使用者视角:

```php
$preset = new FullPreset();                     // 或 MinimalPreset(),或自己的类
$agent  = new Agent($client, $model);
foreach ($preset->tools() as $tool) {
    $agent->tools()->register($tool);
}
```

## 5. Sandbox(安全边界)

```php
final class Sandbox
{
    /** 构造时锁定 root;目录不存在则创建(mkdir 0755, recursive) */
    public function __construct(string $root);

    /**
     * 用户路径 → root 内绝对路径。
     * @throws AgentException 610 路径越界(解析后不在 root 内)
     */
    public function resolve(string $userPath): string;

    public function root(): string;
}
```

解析规则:

1. 相对路径以 root 为基;绝对路径原样参与校验;
2. `realpath`(路径已存在时)否则逐段归一化(处理 `.`/`..`,路径不存在是常态——写文件场景);
3. 校验:解析结果必须等于 `$root` 或以 `$root . '/'` 开头,否则抛 610;
4. 符号链接:realpath 语义天然覆盖——链接指向 root 外即被拒(测试钉住三态:合法/穿越/符号链接)。

## 6. Conversation(会话状态)

```php
final class Conversation
{
    public function append(array $message): void;          # wire 格式消息
    public function messages(): array;
    public function usage(): array;                        # ['prompt_tokens'=>int,'completion_tokens'=>int,'total_tokens'=>int]
    public function accumulateUsage(object $usage): void;  # 从响应 body 累积
    public function reset(): void;                         # /clear 类操作
}
```

- 纯内存状态;会话持久化与长期记忆见 §6.1/§6.2(独立需求,评审记录:N3 后单独立项);
- messages 保持 OpenAI wire 格式纯数组,序列化即请求体,无转换层。

## 7. 异常与错误码(追加 design/10 §5)

`Ws\Http\NanoGpt\AgentException`(继承 `Ws\Http\Exception`),段 **600–699**:

| 码 | 含义 |
| --- | --- |
| 601 | 超过 maxTurns 仍未产出文本 |
| 602 | 未知名工具(模型调用了未注册的 function) |
| 603 | 工具注册重名 |
| 604 | ChatEndpoint 响应结构不符合预期(body->choices[0]->message 缺失) |
| 610 | Sandbox 路径越界 |

### 7.1 记忆(独立需求,评审记录 2026-09-20)

"记忆"拆为两个正交问题,组件态、生命周期、失效策略完全不同:

| | 会话持久化(短期) | 跨会话知识(长期) |
| --- | --- | --- |
| 形态 | messages[] 原样序列化(session json,落 .runtime——草稿区,过期即弃) | 显式记忆条目(memory.json,落 .work——用户明确要求保留,同产出物生命周期) |
| 消费 | /resume 原样回放 | system prompt 附加段(Memory::systemPromptBlock,条目数上限) |
| 失效 | /clear 或过期删除 | 显式管理(/memory remember/forget/list) |

- 组件态:ConversationStoreInterface + FileStore(~80 行);Memory(remember/recall/forget/systemPromptBlock);
- **不做自动提炼**(每次对话尾让模型总结存档):成本/延迟、错记永久污染;只做用户显式写(模型经工具自主写列预留);
- Agent loop 零感知:记忆是 Conversation/壳的职责,Agent 只消费 messages;
- **单独立项**:N3(壳)交付后作为独立需求出设计+实现,不并入本次改进。

## 8. CLI 应用壳(examples/cc-gpt,不在本组件内)

演示组件组装成产品;命令注册表是壳的扩展点,ToolRegistry 是组件的扩展点。

```
examples/cc-gpt/
├── bin/cc-gpt                     # 入口(composer autoload 已覆盖 Ws\Http;壳自身 namespace CcGpt 经 scripts 加载)
├── src/CcGpt/
│   ├── Application.php            # REPL:读输入 → 命令 or Agent->run() 渲染事件流
│   ├── CommandRegistry.php + CommandInterface.php + Command/   # Init/Model/Context/Help
│   ├── Settings.php               # cwd/cc-gpt/.settings.json 读写
│   └── Ui/Output.php + Ui/Input.php
├── config.example.php             # api-key 模板(config.php gitignore)
└── README.md                      # 演示说明 + 扩展指南(加命令/加工具各一节)
```

关键决策:

- **CLI 输入/输出/颜色/表格自实现(~120 行,零三方依赖)**:ANSI 常量表(30–37/90–97 前景、`\033[0m` 复位)+ `posix_isatty(STDOUT)` 降级;表格仅 /model 三列小表(`mb_strwidth` 对齐);输入 `readline()` 存在则用,否则 `fgets(STDIN)`。理由:库总约束"零外部 require"(design/10 §6),示例引入 symfony/console 会自破约束;Output 接口化(`write/writeln/table/colorsEnabled`)保留换渲染后端的演进空间;
- **配置三层**:env(`CC_GPT_API_KEY`) > `config.php` > 提示运行 /init;
- **/model 免费模型选择**:`models()->list()` → `$body->data[]` 过滤 `id` 含 "free",展示 id/name/pricing;
- **/context**:读 `Conversation::usage()` 展示跨轮累积 + 当前模型 + 已注册工具列表;
- **目录布局(评审修订 2026-09-20,勿反复)**:壳项目根 = `examples/cc-gpt/`(执行时 cd 到该目录,与调用者 cwd 无关);三区分离:

  | 路径 | 语义 | 清理语义 |
  | --- | --- | --- |
  | `.settings.json` | 应用配置(/init 写入;apiKey/baseUrl/model) | 不轻易删 |
  | `.work/` | **沙箱 root**(primary):文件工具活动区,agent 产出物存档 | 用户显式清理(拿走产出后) |
  | `.runtime/` | **草稿区**(extra root):中间脚本/临时文件(如"从 json 提取项"的脚本在此建立、执行、结果存档回 .work) | 内容可随时删,目录常驻(运行时自动创建) |

  - 沙箱 root 覆盖链:`--work <dir>` > `<壳根>/.work/`;Sandbox 支持**多 root**(primary + extra,前缀校验遍历全部,§5);
  - `.gitignore`:`examples/cc-gpt/.work/`、`examples/cc-gpt/.runtime/`、`examples/cc-gpt/.settings.json`(含 key);
- **ExecTool(系统命令执行,评审决策)**:需要(草稿区"建脚本→执行→存档"链的最后一环)。安全边界与 HttpGetTool 白名单模式同构:二进制白名单(空表=禁用)、cwd 锁定 .runtime、超时(proc_terminate)、输出截断(2KB);**FullPreset 默认不含**(文件工具无害可默认,命令执行须显式授权——壳装配时注入白名单开启)。
  > **谱系重构(design/25,已实现)**:ExecTool 已特例化为 CommandToolBase 的"自由度拉满"端点(五层防线为其自由槽专属补偿);语义化命令工具(GrepTool 等)与描述文件装载(.tool.json)见 design/25——本节保留为历史决策记录,现状以 design/25 §1 谱系表为准。

### 8.1 ModelSource(模型目录来源,壳层契约)与 Preset 装配

**动机**:模型列表获取方式随服务不同(orcarouter 解析 /models 链接;百炼类可能手动指定);命令集(/xxx)也随环境增减。这些是**配置与产品形态**问题,归属壳层;组件只接收 `string $model` 运行参数,不感知"模型从哪来"。

```php
// 壳层(CcGpt)契约——不进 NanoGpt 组件:
interface ModelSourceInterface
{
    /** @return ModelInfo[] {id, name, pricing} 展示用模型目录 */
    public function models(): array;
}

final class OrcaRouterModelSource implements ModelSourceInterface {}  // models()->list() 过滤 free
final class StaticModelSource implements ModelSourceInterface {}     // config.php 手写列表

// 命令集随 Preset 装配(§4.2):Application::bootstrap(Preset $preset)
//   preset->tools()      → 逐个 ToolRegistry::register()
//   preset->modelSource()→ /model 命令的目录来源(null 时 /model 提示手动 --model)
//   命令集:内建 4 命令恒注册;壳不再按环境增删内建命令(使用者在自己的启动脚本追加)
```

变更归属总结(评审结论,勿反复):

| 变化 | 归属 | 机制 |
| --- | --- | --- |
| 模型目录来源不同 | 壳 | ModelSourceInterface 两内建(OrcaRouter/Static) |
| 能力差异(本地/S3/自定义) | 组件 | ToolInterface + ToolRegistry(§4);S3 = 自定义 Tool,介质自管 |
| 命令差异 | 壳 | CommandInterface + CommandRegistry |
| 出厂内容整体换装/禁用 | 两层 | Preset(§4.2,组件段 tools+modelSource)+ 壳按 Preset 装配命令 |

### 8.2 ModelProvider 适配器(评审修订 2026-09-23,N4 冒烟暴露的架构错误纠正)

**暴露的问题**:N3 实现时 `Application::defaultPreset()` 无条件注入 OrcaRouterModelSource——orcarouter 的服务特定逻辑(`id 含 free` 过滤)被固化成**所有服务**的通用默认。任何其他服务(百炼/自建网关/OpenAI 官方)的 `/model` 都被迫走 orcarouter 语义。这违背本节原始契约("模型来源随服务不同,壳按需注入")。

**评审过程(三轮方案收敛,根因复盘)**:

1. 初修(字符串配置字段 `modelSource: "orcarouter"`)→ 否决:壳变成已知服务硬编码 switch,隐式契约;
2. 二修(ServiceProfileInterface 识别+工厂+注册表+fallback 槽位)→ 否决:为示例壳的几行装配建框架,过度设计;且 host 识别对"使用者知道自己在用什么服务"的场景是多余负担;
3. 根因复盘:**服务绑定的时刻是 /init**——使用者配置 baseUrl 进 .settings.json 的那一刻,服务身份已确定,此后服务特定行为应能从 baseUrl 事实推导,使用者不应再改装配代码或声明字段;
4. 使用者点破关键:`/init` 就是设置模型服务商的地方,服务商适配应当有**专属路径**——适配器模式,而非散落的"来源类"。

**决策(修订 2026-09-23 三次评审:分派 = /init 显式选择,host 嗅探否决)**:

实施时暴露系列错误并逐级纠正(详见记忆案例 7):① 两个适配器塞一文件违反 PSR-4;② **伪适配器**——无抽象层,容错/规范化等共享语义下沉具体类,适配器退化成普通类,名实不符;③ 分派用 `strpos($host, 'orcarouter')` 字符串嗅探——**脆弱魔法**(换域名/代理即失效)、服务知识散落代码、测试只能以字符串比对钉住(测起来别扭的设计=设计错了)。

最终确立:**交互流决定内部分派**——/init 是设置模型服务商的地方,流程 = 先列适配器菜单、用户选择、再按所选适配器收集 key/url:

```
/init
  ① 列出系统支持的适配器:1) orcarouter(free 过滤)  2) generic(OpenAI 兼容,全量列表)
  ② 用户选择 → 按该适配器自报的输入项(prompts)收集 key / baseUrl
  ③ 写入 .settings.json:{ adapter: "orcarouter", apiKey: ..., baseUrl: ... }
```

**三层适配器结构**(接口 + 抽象类 + 具体适配器)与适配器自报契约:

```php
// 落位:examples/cc-gpt/src/CcGpt/ModelProvider/(PSR-4:一文件一类,文件名=类名)
// 契约(Target,§8.1 不变):壳与 /model 只认契约,不感知适配器数量与对象
interface ModelSourceInterface { public function models(): array; }

// 模板层:骨架 = 调用 → 容错 → 规范化(final 锁定)+ 自报契约(菜单/收集/定位用)
abstract class AbstractServiceAdapter implements ModelSourceInterface
{
    final public function models(): array   // final:骨架不可改写
    {
        try {
            return $this->normalize($this->fetch());
        } catch (\Throwable $e) {
            return [];                       // 容错是共享语义,上提至此
        }
    }
    abstract protected function fetch(): array;               // 服务调用(差异)
    abstract protected function normalize(array $raw): array; // 过滤/映射(差异)

    // —— 自报契约(静态;/init 菜单与 settings.adapter 定位的中性依据)——
    abstract public static function id(): string;      // 选择标识(写进 settings.adapter)
    abstract public static function label(): string;   // 菜单展示("orcarouter(free 过滤)")
    /** @return array<int, array{key: string, prompt: string, default?: string}> 输入项自述 */
    abstract public static function prompts(): array;  // 如 apiKey/baseUrl(各适配器不同)
}

// 具体适配器(三个):
final class OrcaRouterAdapter extends AbstractServiceAdapter {}  # /models 解析 + free 过滤
final class GenericAdapter  extends AbstractServiceAdapter {}    # OpenAI 兼容全量列表(不过滤)
final class StaticAdapter   extends AbstractServiceAdapter {}    # 手写档案(settings.models;**不进 /init 菜单**,仅手配)
```

- 适配器描述表(/init 内一个中性表,id → 类名):新适配器 = 一个类 + 表加一行,不碰其他代码(壳层开闭);
- 分派 = 按 `settings.adapter` 查表定位适配器 → ModelSourceInterface;**无 host 字符串嗅探**(已否决);
- **存量兼容**:缺 `adapter` 字段的旧 settings 视为"未选择"——/init 引导重选,**不做 host 反推兜底**(那回到嗅探);
- **/init 重跑**:支持只改适配器(保留 key/url)或全量重配;非交互环境(管道/测试)参数化退化:`/init --adapter <id> <key> <url>`(显式参数优先,无参才进交互菜单);
- **M1 衔接预埋**:模型能力档案(contextWindow/maxOutputTokens,design/24 §6.1)长在适配器的 normalize 里(如 OrcaRouter 读网关扩展字段),不另起配置。

约束(勿反复):

- **共享语义上提抽象层,差异语义下沉具体类**——新增共享行为改 AbstractServiceAdapter,不进具体类;
- **一文件一类(PSR-4)**——文件路径+文件名与 namespace+类名严格一致(项目经 addPsr4 加载 CcGpt,违反即加载失败);
- **不做注册表框架**——描述表 + 自报契约已覆盖;多服务插件化真需求出现时再提炼,演进无损;
- **用户给定配置 = 测试与验证唯一输入源**——禁止虚构服务地址/假想服务商/匿名测试类;超出给定配置的场景先向用户提出;
- `ModelSourceInterface` 命名与位置**不变**(组件契约语义未变)——§8.1 的接口代码块保留原状,本节为实施修订记录。

使用流程(修正后,/init 显式选择驱动):

```
/init                      ← 列适配器菜单 → 选择 → 收集 key/url → 写 {adapter, apiKey, baseUrl}
  → /model 按 settings.adapter 定位适配器(orcarouter → free 过滤;generic → 全量)
/init --adapter orcarouter <key> <url>   ← 非交互参数化形态(管道/测试)
```

变更归属表修订(覆盖 §8.1 首行):

| 变化 | 归属 | 机制 |
| --- | --- | --- |
| 模型目录来源不同 | 壳 | **ModelProvider 三层适配器**(OrcaRouter/Generic/Static,§8.2)+ /init 显式选择(settings.adapter) |

## 9. 测试要点

| 用例组 | 覆盖 |
| --- | --- |
| Sandbox | 合法路径 / `../` 穿越(610)/ 符号链接指向 root 外(610)/ 不存在的写路径归一化 / 多 root(primary + extra 各自可达与互斥) |
| Agent loop(mock,同 PluginRegistryTest 的 requestOn 替身模式) | 双响应序列:先 tool_calls → 工具执行 → role=tool 回填 → 二次调用 → 文本;usage 累积;maxTurns 触发 601;未知名工具 602;finish_reason=length |
| ToolRegistry | 注册/重名 603/未知名 602/jsonSchemas 结构 |
| 内建工具 | Write→Read 往返;ListDir;HttpGet 白名单拒绝;ExecTool 白名单拒绝/超时/成功三态 |
| Conversation | 追加/usage 累积/reset |
| ModelProvider(§8.2) | 模板层容错(fetch 抛 → models() 空,经真实适配器覆盖);StaticAdapter 往返;OrcaRouterAdapter normalize(free 过滤);自报契约(id/label/prompts);settings.adapter 定位分派;**host 嗅探无测试(已否决)** |
| /init 命令 | 交互流:菜单选择 → 按 prompts 收集 → settings 写入 {adapter, apiKey, baseUrl};参数化退化路径(--adapter);重跑只改 adapter 保留 key/url;存量无 adapter 字段视为未选择 |
| Preset | FullPreset 含 4 工具 + modelSource(不含 ExecTool);MinimalPreset 零工具 + null;自定义 Preset 增删工具后 jsonSchemas 生效 |
| 壳(tests/Unit/Ccgpt/) | CommandRegistry 注册/未知名;Settings 读写往返、缺字段;ModelSource 两内建;--work 覆盖与默认 .work 定位;不测 UI 与真实网络 |
| 隔离性 | core/functional/Plugin 无对 `Ws\Http\NanoGpt\` 的引用(沿用 design/17 §6 隔离测试模式) |

## 10. 实施步骤(N1–N5,测试先行)

| # | 步骤 | 交付物 | 验证 |
| --- | --- | --- | --- |
| N1 | 组件核心 | ToolInterface/ToolRegistry/Sandbox + 4 内建工具 + Conversation + Preset(§4.2)+ AgentException | Sandbox 三态 + Registry/Preset 单测绿 |
| N2 | Agent loop | Agent.php | mock 双响应序列单测 |
| N3 | CLI 壳 | examples/cc-gpt 全部(含 ModelSource 两内建) | 壳单测 + /help /init /model(mock)/context 路径覆盖 |
| N4 | 集成冒烟 | 真实 orcarouter key 全链 | GET /models 过滤 free → /model 选择 → 带工具对话 |
| N5 | 文档收口 | 主 README/CHANGELOG 更新、design/README 索引同步 | — |

**风险与对策**:orcarouter 的 tools 支持度未知(free 模型可能不支持 function calling)→ N4 冒烟验证;若不支持,Agent 保留 tools 传参但文档标注"需支持 tools 的模型",纯对话路径不受影响。
