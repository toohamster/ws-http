# 14 场景脚本 Schema v2 设计(functional:场景契约)

> 层归属:**functional**。使用者:接口测试工程师、场景脚本作者。

**简单路径**——最小合法场景只有 5 行,settings/auth/proxy/extract/assertions 全部可选:

```jsonc
{
  "id": "smoke-001",
  "name": "冒烟测试",
  "steps": [
    { "type": "http", "id": "ping", "name": "首页可用",
      "url": "https://httpbin.org/status/200", "method": "GET" }
  ]
}
```

**简单路径使用者只需要**:steps 里写 url/method,需要提取就加 `extract`、需要校验就加 `assertions`。settings/auth/proxy/delay 属于"复杂的事情必须可能"的部分,按需查对应小节。

> 范围:`Scenario` 场景脚本的 JSON 正式契约——字段定义、约束、校验规则、对象映射。
> 旧 v1 参考:`src/Ws/Http/Automated/example.json`(夜点娱乐场景)。按 D1 决策**不做 v1 兼容**;v2 修复 v1 的已知问题:
> - 字段混乱:请求头 v1 中同时存在 `header`/`headers` 两种拼写;处理器字段 v1 中 `postProcessors` 与 `processors` 混用;
> - 语义重叠:authorization 既要表达 Basic 又要表达自由头,规则隐晦;
> - 缺失定义:断言操作符、提取失败策略、步骤上下文(如重试、跳过)无契约;
> - 变量占位符:`${var}` 与 `$var` 并存,`$loginCookie` 形态易与正文字面量冲突。

## 1. 顶层结构

```jsonc
{
  "$schema": "ws-http/scenario-v2",
  "id": "scenario-001",                    // 必填,非空字符串,场景唯一标识
  "name": "夜点娱乐-预订流程",               // 必填
  "description": "登录 → 查门店 → 下单",     // 可选
  "settings": {                            // 可选,场景级默认(见 §2)
    "timeout": "15s",
    "failFast": true,
    "cookieStore": "memory",
    "proxy": null
  },
  "variables": [                           // 可选,变量初始值(design/15)
    { "name": "city",   "value": "440100" },
    { "name": "searchKtvName", "value": "金柜" }
  ],
  "steps": [ /* Step[], 见 §3 */ ]
}
```

### 1.1 settings 字段

| 字段 | 类型 | 默认 | 说明 |
| --- | --- | --- | --- |
| `timeout` | string | `"30s"` | 场景级默认请求超时;步骤可覆盖 |
| `failFast` | bool | `true` | 等价旧 Task type=1:任一步骤失败即终止(替代 type 字段,语义自明) |
| `cookieStore` | string | `"memory"` | `memory` = 内存 jar 跨步骤传递;`none` = 不管理;`file:<path>` = 持久化(design/16) |
| `proxy` | Proxy\|null | `null` | 场景级代理默认;步骤可覆盖 |

校验:未知字段**拒绝**(严格模式,防止拼写错误静默失效——v1 中 `headers` vs `header` 就是教训)。

## 2. Step:两种步骤类型

`type` 字段判别(discriminated union):

### 2.1 type = "http"(HTTP 请求步骤)

```jsonc
{
  "type": "http",
  "id": "step-1471348763956",              // 必填,场景内唯一
  "name": "微信用户登录token获取",           // 必填,报告显示用
  "url": "http://api.example.com/user/login",  // 必填,支持 ${var}
  "method": "POST",                         // 必填,大写;支持 Method 常量全集
  "timeout": "15s",                         // 可选,覆盖场景级;格式见 §4.1
  "headers": {                              // 注意:统一为 headers(修复 v1 拼写混乱)
    "X-KTV-User-Token": "${token}",
    "cookie": "${loginCookie}"
  },
  "auth": {                                 // 可选,替代 v1 authorization(见 §4.2)
    "type": "basic",                        // "basic" | "bearer" | "header"
    "user": "foo",                          // basic 用
    "password": "bar",                      // basic 用
    "token": "${token}",                    // bearer 用 → Authorization: Bearer <token>
    "value": "Digest xxx"                   // header 用 → 直接作 Authorization 头值
  },
  "proxy": {                                // 可选,覆盖场景级;结构见 §4.3
    "type": "socks5",
    "address": "127.0.0.1",
    "port": 1080,
    "tunnel": false,
    "auth": { "user": "", "password": "" }
  },
  "body": {                                 // 可选;请求体定义,见 §4.4
    "mode": "json",                         // "params"|"urlencoded"|"json"|"xml"|"html"|"text"|"binary"
    "content": [                            // params/urlencoded:键值数组
      { "key": "city", "value": "${city}" }
    ]
    // 或 json/xml/html/text: "content": "{\"ktvid\":\"${ktvId}\"}"  原始串
  },
  "extract": [ /* VarRule[], 变量提取,design/15 §3 */ ],
  "assertions": [ /* AssertRule[], 断言规则,design/12 §5 */ ]
}
```

**method 与 body 的合法性约束**(由加载期校验,替代旧版运行时 throw):

| method | body.mode 允许值 |
| --- | --- |
| GET / HEAD | `params` 仅;其余 mode 拒绝(code:402) |
| POST / PUT / PATCH / DELETE / OPTIONS / 其他 | 全部 mode |
| CONNECT / TRACE | body 必须缺省 |

### 2.2 type = "delay"(延时步骤)

```jsonc
{
  "type": "delay",
  "id": "step-wait-1",
  "name": "等待下单窗口",
  "duration": "5s"          // 必填;格式同 §4.1,最大 300s,上限防误配
}
```

> v1 用 `type:1/2` 数字 + `delay` 数字秒;v2 改字符串判别 + duration 统一时间格式。

## 3. 对象映射(JSON → PHP)

| JSON | PHP 对象 | 备注 |
| --- | --- | --- |
| 顶层 | `Scenario` | 含 meta + settings + VariableScope 初始值 + Step[] |
| steps[i] (http) | `HttpStep` | body/extract/assertions 解析为子对象数组 |
| steps[i] (delay) | `DelayStep` | duration 归一为 int 秒 |
| extract[i] | `VarRule` | design/15 |
| assertions[i] | `Assertion` | design/12 §3,复用同一值对象 |
| settings.timeout 等 | `RequestOptions` 片段 | 步骤级设置在 Runner 中合并:步骤 > 场景 > 内置默认 |

两者共用抽象基类/接口:

```php
interface Step
{
    public function id(): string;
    public function name(): string;
}
```

## 4. 子结构详细定义

### 4.1 时间字符串格式

```
格式:/^\d+(\.\d+)?(ms|s|m)?$/
缺省单位 = s;上限:timeout 300s / duration 300s
"15" → 15s ; "500ms" → 0.5s ; "1.5s" → 1.5s ; "2m" → 120s
非法格式 → 校验失败 code:402
```

timeout 为 0 或负数拒绝(修复 v1 静默回退 30s 的隐式行为——v2 显式报错)。

### 4.2 auth 结构(修复 v1 authorization 条件反转 bug B3,规则重设计)

| type | 必填字段 | 行为 |
| --- | --- | --- |
| `basic` | user, password | `RequestOptions::withAuth(user, pass, CURLAUTH_BASIC)` |
| `bearer` | token | 请求头 `Authorization: Bearer {token}` |
| `header` | value | 请求头 `Authorization: {value}`(兼容 Digest 等自定义 scheme) |

与 v1 差异:v1 无 type 字段时靠"有没有 body 字段"猜意图;v2 显式必填 type,未知 type 校验拒绝。

### 4.3 proxy 结构

```jsonc
{ "type": "http", "address": "...", "port": 1080, "tunnel": false,
  "auth": { "user": "...", "password": "..." } }
```

- `type` 枚举:`http` / `http1.0` / `socks4` / `socks4a` / `socks5` / `socks5.hostname`,映射 CURLPROXY_*;
- `port` 必为 1–65535 整数(v1 是字符串,trim 后传 int,隐含类型转换);`tunnel` 必为 bool;
- v1 的条件反转 bug(B3)在 v2 语义下不存在:结构存在即生效。

### 4.4 body 结构(mode 判别)

| mode | content 形态 | 产出(L1 Body) | 请求 Content-Type |
| --- | --- | --- | --- |
| `params` | `{key,value}[]` | multipart 数组(与 v1 一致,cURL 自动表单) | multipart/form-data(cURL 自动) |
| `urlencoded` | `{key,value}[]` | `Body::form()` | application/x-www-form-urlencoded |
| `json` | string(JSON 文本) | `Body::raw(str, ...)` 原样发送 | application/json |
| `xml` | string | 同上 | application/xml |
| `html` | string | 同上 | text/html |
| `text` | string | 同上 | text/plain |
| `binary` | string(base64) | 解码后发送 | application/octet-stream |

> v1 有 `raw-json/raw-xml/raw-textxml/raw-html/raw-text` 五种前缀命名与 `raw-binary` 抛异常的处理;v2 统一为单一 `mode` 枚举,`binary` 明确支持(修复 v1 的"未支持"),`textxml` 合并入 `xml`(v1 中两形态实际等价,保留一个)。
> `json` mode 只做透传不二次编码:content 是**原始 JSON 字符串**(支持内嵌 `${var}` 替换后再发送,顺序见 design/15 §4)。

### 4.5 extract 与 assertions 字段

- `extract`: `VarRule[]`,契约见 design/15 §3;
- `assertions`: `AssertRule[]`,契约见 design/12 §5(fromArray 映射)。v2 统一字段名 `assertions`(v1 的 `postProcessors.asserts` 嵌套结构废弃,提取与断言平铺为两个一级字段——它们语义独立,不应打包在"处理器"概念下)。

## 5. 校验规则汇总(ScenarioParser)

加载期一次性完成全部校验,失败抛 `AutomatedException(code:401/402)`,消息含 JSON Pointer 路径:

| # | 规则 | code |
| --- | --- | --- |
| V1 | JSON 语法合法 | 401 |
| V2 | 必填字段齐全(id/name/steps;step 的 id/name/url/method) | 402 |
| V3 | `settings`、`headers`、`auth`、`proxy`、`body`、`extract`、`assertions` 无未知字段(严格模式) | 402 |
| V4 | step.id 场景内唯一 | 402 |
| V5 | method ∈ Method 常量;method 与 body.mode 合法组合(§2.1 表) | 402/404 |
| V6 | 时间字符串格式合法且在限额内 | 402 |
| V7 | proxy.type ∈ 枚举;port ∈ [1,65535];auth.type ∈ 枚举 | 402 |
| V8 | variables[].name 唯一且合法(设计/15 §2 命名规则) | 402 |
| V9 | 全部 assertions 的 json 路径、全部 extract 的 attr 路径通过 `ExpressionEvaluator::validate()`(提前暴露脚本错误) | 402 |
| V10 | extract[].var 引用的变量名必须已声明(variables 或前置步骤 extract)——**保守策略:加载期不校验跨步骤引用,运行期未声明变量按 design/15 §4 处理** | — |

> 校验失败消息格式:`{jsonPointer}: {原因}`,如 `/steps/2/body/mode: "form" is not a valid mode`。

## 6. 完整 v2 示例(对应 v1 夜点娱乐场景)

```jsonc
{
  "$schema": "ws-http/scenario-v2",
  "id": "scenario-yedian-001",
  "name": "夜点娱乐-预订流程",
  "settings": { "timeout": "15s", "failFast": true, "cookieStore": "memory" },
  "variables": [
    { "name": "city", "value": "440100" },
    { "name": "searchKtvName", "value": "金柜" }
  ],
  "steps": [
    {
      "type": "http", "id": "login", "name": "微信用户登录获取 token",
      "url": "http://api.example.com/user/oauthlogin",
      "method": "POST",
      "headers": {
        "X-KTV-Vendor-Name": "1d55af1659424cf94d869e2580a11bf8",
        "X-KTV-Application-Platform": "1",
        "X-KTV-Application-Name": "eec607d1f47c18c9160634fd0954da1a"
      },
      "body": { "mode": "json", "content": "{\"type\":\"wechat\",\"openid\":\"dddddddok...\"}" },
      "extract": [
        { "var": "token",       "source": "json",   "path": "$.token" },
        { "var": "loginCookie", "source": "header", "name": "Set-Cookie" }
      ]
    },
    {
      "type": "http", "id": "userinfo", "name": "根据 token 获取用户信息",
      "url": "http://api.example.com/user/info",
      "method": "GET",
      "headers": { "X-KTV-User-Token": "${token}" },
      "assertions": [
        { "source": "status", "op": "eq", "expected": 200 },
        { "source": "json", "path": "$.result", "op": "eq", "expected": 0 }
      ]
    },
    {
      "type": "http", "id": "search", "name": "按名称搜索 KTV",
      "url": "http://api.example.com/booking/xktvsearchlist",
      "method": "GET",
      "headers": { "X-KTV-User-Token": "${token}" },
      "body": { "mode": "params", "content": [
        { "key": "offset", "value": "0" },
        { "key": "city", "value": "${city}" },
        { "key": "name", "value": "${searchKtvName}" }
      ]},
      "extract": [ { "var": "ktvId", "source": "json", "path": "$.list[0].xktvid" } ],
      "assertions": [
        { "source": "status", "op": "eq", "expected": 200 },
        { "source": "json", "path": "$.total", "op": "gt", "expected": 0 }
      ]
    },
    { "type": "delay", "id": "wait", "name": "等待", "duration": "2s" },
    {
      "type": "http", "id": "submit", "name": "提交订单",
      "url": "http://api.example.com/booking/submitorder_new",
      "method": "POST",
      "headers": { "X-KTV-User-Token": "${token}", "cookie": "${loginCookie}" },
      "body": { "mode": "json", "content": "{\"ktvid\":\"${ktvId}\",\"couponid\":0}" },
      "assertions": [ { "source": "status", "op": "eq", "expected": 200 } ]
    }
  ]
}
```

与 v1 的字段名对照表(供编写参考,不做迁移工具):

| v1 | v2 |
| --- | --- |
| `body.vars` | 顶层 `variables` |
| `body.requests` | 顶层 `steps` |
| `type: 1/2`(Task) | `settings.failFast`(bool) |
| `type: 1/2`(request) | `type: "http"/"delay"` |
| `header` / `headers` 混用 | 统一 `headers` |
| `authorization` | `auth`(type 显式) |
| `dataMode` + `data` | `body.mode` + `body.content` |
| `postProcessors.setVar` | 平铺为 `extract`(字段名 `attr` → `path`,统一 JSONPath) |
| `postProcessors.asserts` | 平铺为 `assertions` |
| `${var}` / `$var` 并存 | 统一 `${var}`(design/15 §2) |

## 7. 测试要点

| 用例组 | 覆盖 |
| --- | --- |
| 解析 | §6 示例端到端解析为对象树;字段默认值(settings 缺省) |
| 校验 | V1–V9 逐条构造非法样本,断言 code 与 JSON Pointer;未知字段被拒(headers 拼写错误场景) |
| method×mode | §2.1 合法/非法组合矩阵 |
| 时间 | §4.1 全格式;"-3s"、"10m"、超限拒绝 |
| 对照 | v1 example.json 语义等价的 v2 脚本(§6)解析结果与预期对象树快照一致 |
