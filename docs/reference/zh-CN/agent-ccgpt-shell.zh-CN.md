[English](../agent-ccgpt-shell.md) | [中文版本](./agent-ccgpt-shell.zh-CN.md)

# cc-gpt 壳(参考 CLI)

> 模块:`examples/cc-gpt` — `CcGpt\Application`、`Context`、`Settings`、`Command\*`、`Ui\*`、`ModelProvider\*`
> NanoGpt 组件的首个消费方;兼任嵌入该组件的参考实现。

## Overview

`cc-gpt` 是一个终端 REPL,把完整的 NanoGpt 栈——agent、工具、会话、记忆、技能——装配到一个命令面之后。它演示了自定义壳需要的每一个集成点:

- **装配**(`Application::bootstrapAgent`):`settings.adapter` → 厂商传输类;同一个共享传输实例服务 agent、压缩与窗口探测;
- **目录布局**(壳根,与 CWD 无关):

```
.settings.json   应用配置(/init 写入;gitignored)
.work/           沙箱根 — 文件工具活动区(--work <dir> 可覆盖)
.runtime/        草稿 — agent 中间脚本;自动创建,可随时清空
tools/           声明式工具描述(*.tool.json)— 启动时装载
skills/          技能包(*.skill.json)— 用户资产,可分享,不自动创建
```

- **配置链**:env(`CC_GPT_API_KEY`、`CC_GPT_BASE_URL`)> `.settings.json` > 提示运行 `/init`。

## Key Components

### 命令

| 命令 | 行为 |
| --- | --- |
| `/init [key] [url]` | 交互式:适配器菜单 → 逐项收集 → 写 `.settings.json`。非交互:`/init --adapter <id> <key> <url>` |
| `/model [id\|index]` | 目录列表(`ModelInfo` 表)/ 切换。切换后:对账——预算重建 → 三态(确认 / 警告 / 超限菜单 `[c]ompact [r]ewind [k]eep`);窗口未知 → 级联探测(探测 → 接受/手输/跳过) |
| `/skill [name args... \| name --info]` | 运行已声明技能(渲染指令成为本轮 user 消息)/ 预览模板 |
| `/compact [keep]` | 手动压缩(保留 N 轮,默认 2);自动保存会话 |
| `/rewind` | 列 user 检查点(摘要消息标记)→ 截断到所选边界 |
| `/clear` | 删除当前会话文件,生成新 session id,重置会话 |
| `/resume [id]` | 列会话 / 恢复快照(消息、用量、system) |
| `/memory ...` | `remember <text>` / `list` / `forget <n>` — 显式长期记忆 |
| `/context` | 双口径:累计用量(费用)+ 当前窗口占用(预算估算 %) |
| `/help`、`/quit` | 命令列表 / 退出(Ctrl-D 亦可) |

非命令输入进入 agent;每轮**先跑自动压缩**(`settings.compactAuto`,默认开),再渲染事件流(文本 / 工具调用 / 错误)。

### 模型提供器适配器(`CcGpt\ModelProvider`)

传输之上的薄壳——目录解析归 `Transport::capabilities()`:

```php
abstract class AbstractServiceAdapter implements ModelSourceInterface
{
    final public function models(): array;         // 委托 transport->capabilities()['models'],容错
    abstract public static function id(): string;      // 自报 → settings.adapter
    abstract public static function label(): string;   // /init 菜单行
    abstract public static function prompts(): array;  // /init 输入收集
}
```

内建:`OrcaRouterAdapter`(free 模型过滤)、`GenericAdapter`(任意 OpenAI 兼容服务)。`settings.adapter` 分派到匹配的**厂商传输类**;未选/未知 → `GenericOpenAiTransport`(逃生门)。

### Settings

`.settings.json` 键:`adapter`、`apiKey`、`baseUrl`、`model`、`modelProfiles`(每模型 `contextWindow` + provenance——级联探测写入或手填)、`contextWindow`(兜底)、`compactAuto`、`httpAllowHosts`(非空即启用网络工具)、`models`(手写目录 → 静态适配器)。

## Usage Examples

### 首次运行

```shell
cd examples/cc-gpt && /usr/local/bin/php74 bin/cc-gpt

you> /init
select model provider adapter:
  1) orcarouter (free model filter)
  2) generic (any OpenAI-compatible service)
adapter [1-2]: 1
API key: sk-...
Base URL [https://api.orcarouter.ai/v1]:
adapter set to orcarouter; settings saved to .../.settings.json
```

### 日常使用

```
you> /model                       # 浏览目录,按序号切换
you> /skill json-report data.json # 运行已声明技能
you> 分析刚才那个文件               # 自由输入 → agent 循环
⚙ read_file {"path":"..."}
  ↳ ...
you> /context                     # 用量 + 窗口双口径
you> /compact 1                   # 保留 1 轮,其余摘要
```

### 嵌入壳模式(自定义装配)

```php
use CcGpt\Application;

$app = new Application('/repo/root', $output, $input, '/custom/work/dir');
$app->bootstrapAgent($sandbox);           // 可重跑(如 /init 写完配置后)
$app->run();                              // REPL;或直接驱动 $app->context()->agent
```

## Configuration

| 键 | 效果 |
| --- | --- |
| `compactAuto` | `false` 关闭每轮前自动压缩 |
| `httpAllowHosts` | 非空数组启用 `http_get`/`api_test`/`api_fetch`/`api_json_post`(该白名单) |
| `modelProfiles.<id>.contextWindow` | 每模型窗口(驱动预算与对账) |

## Dependencies

- **依赖**:整个 NanoGpt 组件(agent、会话、记忆、技能)、`Ws\Http\NanoGpt\Transport\*`(厂商类)、`Ws\Http\NanoGpt\DescriptorToolLoader`(tools/)、`Sandbox`。
- **被依赖**:无(叶子/参考示例)。

可运行冒烟:`examples/cc-gpt/bin/smoke`(三链:models / chat / tools,真实网络)与 `examples/cc-gpt/bin/smoke-d2`(transport→agent 链)。

设计详情:[design/21-NanoGpt设计.md](../../../design/21-NanoGpt设计.md) §8。
