# cc-gpt — 基于 ws-http NanoGpt 组件的 agent CLI

生产级 agent 命令行(design/21):OpenAI 协议对话 + 命令系统 + 工具调用。
组件(`Ws\Http\NanoGpt\`)与壳(`CcGpt\`)分离:组件可独立复用,壳演示组装姿态。

## 运行

```bash
cd <你的工作目录>            # 产出文件与设置都落在 ./cc-gpt/(工作目录锚定)
php <repo>/examples/cc-gpt/bin/cc-gpt
```

配置三层(优先级递减):

1. 环境变量:`CC_GPT_API_KEY` / `CC_GPT_BASE_URL`;
2. `cc-gpt/.settings.json`(/init 写入);
3. 未配置 → 启动时提示 `/init <key> [baseUrl]`。

## 内建命令

| 命令 | 行为 |
| --- | --- |
| `/init <key> [baseUrl]` | 写入 API key/base URL 到 `.settings.json` |
| `/model [index|id]` | 有 ModelSource 时列模型目录(免费模型)并切换;否则提示手动指定 |
| `/context` | 跨轮 token 用量 + 当前模型 + 已注册工具 |
| `/help` | 命令列表 |
| `/quit` | 退出(或 Ctrl-D) |

其余输入原样发给模型(agent loop:文本回复或工具调用,事件流渲染)。

## 目录三区(壳项目根 = examples/cc-gpt,与调用者 cwd 无关)

```
.settings.json   应用配置(/init 写入;不轻易删,gitignore)
.work/           沙箱 root:文件工具活动区,agent 产出物存档(--work <dir> 可覆盖)
.runtime/        草稿区:中间脚本/临时文件;目录常驻(运行时自动创建),内容可随时清
```

## 内建工具与安全边界

| 工具 | 边界 |
| --- | --- |
| read_file / write_file / list_dir / **delete_file** | Sandbox 绝对边界:只能在 .work / .runtime |
| http_get | 域名白名单(默认空 = 禁用) |
| exec | 五层防线:二进制白名单(默认禁 rm/mv/sh/sudo)+ 兜底黑名单 + shell 操作符拒绝 + 任意代码入口拒绝(php -r / node -e 等——要跑代码先写脚本文件到 .runtime)+ 超时/截断。**尽力保证**,文件操作请走文件工具 |

## 工作原理:模型怎么知道调用工具?

一句话:**决策在模型,执行在 Agent**。你输入一句话后:

```
你 → 请求①[对话 + 工具说明书] → 模型语义判断"需要读文件" → 返回 tool_calls
  → Agent 执行 read_file(安全防线在这里) → 结果回填进对话
  → 请求②[含工具结果] → 模型组织最终回复 → 你看到答案
```

对使用者的实际影响:模型不调用你的工具 → 先查工具的 `description()` 质量;参数填错 → 查参数 schema;连调不停 → `maxTurns`(默认 8)兜底。

完整讲解(六站旅程/常见误解/写好说明书的自检清单)见 [docs/nanogpt-tool-invocation.md](../../docs/nanogpt-tool-invocation.md)。

## 扩展指南

### 加一个命令(壳扩展点)

```php
// 1. 实现 CommandInterface
final class Clear implements \CcGpt\CommandInterface
{
    public function name(): string { return 'clear'; }
    public function description(): string { return 'Reset conversation'; }

    public function execute(array $args, \CcGpt\Context $context): bool
    {
        $context->agent?->conversation()->reset();   // PHP 7.4:不用 ?->
        if ($context->agent !== null) {
            $context->agent->conversation()->reset();
        }
        $context->output->writeln('conversation cleared', 'green');

        return true;
    }
}

// 2. 在启动脚本注册(bin/cc-gpt 的 Application 构造后):
$app->commands()->register(new Clear());
```

### 加一个工具(组件扩展点)

```php
// 1. 实现 ToolInterface(name/description/jsonSchema/execute)
final class S3ReadTool implements \Ws\Http\NanoGpt\ToolInterface
{
    public function name(): string { return 's3_read'; }
    public function description(): string { return 'Read an object from S3'; }
    public function jsonSchema(): array { /* parameters schema */ }
    public function execute(array $args): string { /* 介质自管:S3 SDK 或 core Request */ }
}

// 2. 注册到 Agent:
$agent->tools()->register(new S3ReadTool());
```

### 调整 exec 可信名单(Preset 加减)

```php
// bin 启动脚本 / 自己的组装代码里:
$preset = (new \Ws\Http\NanoGpt\FullPreset($sandbox))
    ->withExec(array_merge(
        \Ws\Http\NanoGpt\FullPreset::defaultExecBinaries(),
        ['python3']          // 加
    ));
// 或传入自己的名单(减:不传 php/node);破坏性命令(rm/mv/sh/sudo)有兜底黑名单,
// 即使误入白名单也拒——删除请走内建 delete_file(沙箱绝对边界)。
```

### 换模型目录来源(壳扩展点,design/21 §8.1)

`ModelSourceInterface` 两内建:`OrcaRouterModelSource`(models()->list() 过滤 free)/ `StaticModelSource`(手写列表)。自定义 = 实现接口,注入 `/model` 命令。

## 与组件的边界

- 组件(`src/Ws/Http/NanoGpt/`):agent loop / 工具协议 / 沙箱 / 会话,不依赖 CLI;
- 壳(本目录):命令系统 / REPL / 配置 / UI,不进组件;
- 沙箱:`cc-gpt/` 是文件工具唯一可写目录(穿越/符号链接均被拒)。
