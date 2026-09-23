# 调试与根因分析教训(按真实案例归档)

## 案例 6:表达式引擎三处真 bug + 两处测试语义错(S6,2026-09-11)

**真 bug(实现错)**:
1. Parser 不接受裸 identifier 开头(`a.b` 省略 `$`)——循环前补首 member 步骤;
2. Lexer 无负号数字支持(`-1` 词法失败)——`-`+digit 分支;
3. Lexer 不认 UTF-8 键(`$.名`)——identifier 字符集加 `\x80-\xFF`(UTF-8 续字节全在此区间,单字节扫描即可覆盖)。

**测试语义错(非实现错)**:
4. `array_keys(all())` 期望原始下标——但 EvaluationResult 构造器 array_values 重排索引,下标断言必然错位;**断言用内容(first()['key'])而非重排后的索引**;
5. `count($.l)=2`——member 返回节点本身(整个数组=1 个节点),元素级须 `$.l[*]`;这是设计语义,不是 bug。

**方法论沉淀**:组件单测(looseEquals/evalFilterExpr)都绿但端到端失败时,差异必在"组装链路"(Parser 产出结构 vs 求值器期待结构)——此时加一个端到端诊断测试暴露真实行为,比逐层反射探针更快;诊断测试可保留为回归锁。

## 案例 1:parse_url 不是字节安全的(S4,2026-09-11)

**现象**:`UrlKit::encodeUrl("http://x/search?name=张三")` 产出 `name=%E5%BC%A0%E4%B8_`(张三被截断成 `张�_`)。

**根因推演链**(正确路径,勿跳步):
1. 坏输出在 parse_url 阶段就出现了:`parse_url` 返回的 query 已经是 `'name=张�_&...'` —— 损坏发生在拆解,不在重编码;
2. 我先在"query 重编码"上修了两版(检测高位字节→parse_str+http_build_query),全部无效 —— 因为损坏在更上游;
3. 结论:**parse_url 内部按单字节扫描,UTF-8 多字节序列会被破坏。这是硬事实,无法通过下游处理挽救。**

**正确修法**:进入 parse_url **之前**把非 ASCII 字节预编码为 ASCII:
```php
preg_replace_callback('/[^\x00-\x7F]+/u', fn($m) => rawurlencode($m[0]), $url)
```
之后 parse_url 安全拆解,query 保持已编码形态原样输出(不做 decode/encode 往返)。

**通用教训**:
- `parse_url` 对未编码非 ASCII(中文/emoji/GBK)不安全 —— 必须先 rawurlencode 预处理;
- PHP 内置编码函数足够:rawurlencode / http_build_query / parse_str / parse_url,不要自实现字节级处理(旧代码的 bin2hex 键技巧已废弃);
- query 已编码(纯 ASCII)时**原样保留**,避免 decode→encode 往返造成二次编码(`%89` → `%2589`);
- 修 bug 前先定位**损坏发生的确切阶段**(打印中间产物),而不是在下游反复补救。

## 案例 5:cURL 非 POST 体方法的字符串 body 需要 Content-Type(S5,2026-09-11)

**现象**:集成测试 PUT 字符串 body 到 httpbin,`data` 为空串;POST 同样写法正常。

**二分定位**(3 组对照实验):`no-headers` ✗ / `only-expect` ✗ / `expect+ct` ✓ / `only-ct` ✓ —— 差异是 Content-Type。

**根因**:cURL 只对 POST 自动补 `Content-Type: application/x-www-form-urlencoded`;`CURLOPT_CUSTOMREQUEST=PUT` + `CURLOPT_POSTFIELDS` 字符串且无 Content-Type 时,部分服务端(httpbin)按无类型 body 拒收。**修复**:体方法 + 字符串 body + 用户未指定 Content-Type → 补 `text/plain`。

**附带教训**:httpbin `/redirect/N` 最终落在 `/get`(不是外部 URL);断言用 `curlInfo['redirect_count']` 验证重定向发生。

## 方法论:cURL 行为类问题用"最小二分实验"定位

库代码不工作时,先脱离库用原生 curl_setopt_array 复现(本案例一次就复现了),再二分变量(逐个加减头/选项)。库代码 + 集成测试的失败可能来自:cURL 隐式行为(如自动 Content-Type 只对 POST)、服务端宽容/严苛差异 —— 不在最小复现中确认,容易被库代码表面逻辑误导。



`http_build_query(['a'=>['b'=>'c']])` 产出 `a%5Bb%5D=c` —— `[`/`]` 按 RFC1738 编码,**不是 bug**。设计文档说"拍平为 a[b]=c"指的是键结构;服务端解码后即还原。测试断言用 `%5B%5D` 形态。

## 案例 3:不要锁死 json_last_error() 的具体 errno(S4)

`json_decode('{"broken:')` 实测 errno=3(CTRL_CHAR)而非 4(SYNTAX)—— 具体错误码随截断形态/PHP 版本变化。测试断言用 `assertNotSame(JSON_ERROR_NONE, ...)` + 错误消息非空。

## 案例 4:buildHttpQuery 递归条件遗漏普通对象(S4)

条件写成 `is_array($v) || ($v instanceof Traversable)` 会把 stdClass 当标量跳过 —— stdClass 不实现 Traversable。正确:`is_array($v) || is_object($v)`(CURLFile 提前分支排除),对象经 get_object_vars 转数组再递归。

## 方法论:批量分析再动手(用户明确要求)

测试失败时**不许"改一行跑一次"循环**:先全量读失败详情,在纸面推演每个失败的根因与层级(测试预期错 vs 实现错 vs 环境事实),列出修正清单给用户确认,然后一次改完。本案例中 4 个失败里 3 个是不同性质的根因,混在一条循环里会掩盖真 bug(案例 1 被我误修了两次下游)。

## 方法论:诊断信息一次拿全(用户明确要求,2026-09-11)

**禁止用 `head -N` / `tail -N` / grep -A3 截断诊断输出**:跑测试/看报错必须**一次性拿全部输出**,不能"看第一条→修→再跑→看下一条"。截断式查看的坏处:15 个错误每次只看一个,循环 15 次还可能误判共性;全量输出一次摆在眼前,才能批量归因(哪些同根因、哪些是独立 bug、哪些是测试预期错)。

执行方式:
- 测试:`phpunit 2>&1` 全量输出直接读(不接管道截断);
- 长输出确实需要过滤时,一次 grep 拿全部匹配(不加 head/tail),或落文件后完整读;
- 此原则同样适用于编译错误、静态分析输出、日志排查。

## 方法论:测试替身写成专属类,不用匿名类(用户明确要求,2026-09-11)

测试里的 fake/mock **定义为独立测试工具类**(如 tests/Unit/Core/FakeCurlRequest.php),不用匿名类:
- 可读、可校验、后续步骤可复用(S9 引擎测试的 RequestFactory mock 将直接复用);
- 引用传递坑:PHP 引用绑定不能穿过"函数参数 → 数组字面量元素 → 构造参数"多层传递,会值拷贝断裂 —— fake 用 public static 捕获数组或实例方法读取,彻底避开引用链;
- fake 构造签名不要耦合父类构造方式(父类 withOptions 用 clone 而非 new static,是库代码侧的配套修正)。

## 案例 7:伪适配器 + PSR-4 违反——设计模式名实不符与文件组织破坏(用户定性"极严重",2026-09-23)

**两个叠加错误**(design/21 §8.2 ModelProvider 实施时):

1. **PSR-4 违反**:两个适配器类(OrcaRouterAdapter/StaticAdapter)塞进一个 `Adapters.php`——文件名≠类名,自动加载直接失败(测试 3 个 Class not found)。CcGpt 经 `addPsr4('CcGpt\\', .../src/CcGpt)` 加载(bootstrap.php/bin/cc-gpt 注释里写得明明白白),PSR-4 是项目能跑的前提;
2. **伪适配器(设计架构缺陷,比 1 更严重)**:类叫 Adapter 却**没有适配器结构**——无接口+抽象类+具体类的三层,无模板方法;容错(失败→[])与输出规范化(字段提取/过滤)这些**所有适配器共享的语义**被复写进每个具体类,第三个适配器必然复制粘贴漂移。

**对适配器模式的正确理解(用户拷问后确认)**:

- 正统形态 = **接口(Target)+ 抽象类(模板层,接口+抽象类+适配器类三层)+ 具体适配器**;抽象层用 `final public` 锁骨架(调用→容错→规范化的模板方法),具体类只填服务差异空(fetch()/normalize());
- 只有具体类没有抽象层 = 适配器退化成普通类,**名实不符比没有模式更糟**(误导下一个读者以为发生了接口转换);
- 判断标准:共享语义上提抽象层,差异语义下沉具体类;"名词先行、实体后凑"(讨论中确立"适配器"字眼后让代码迁就名词而不审视结构)是错误源头。

**教训(结构性约定优先于内容,再次验证——同 HttpGetTool 未 staged 教训同构)**:

- 凡新建/迁移/改名 PHP 类文件,第一自检项 = **文件路径+文件名与 namespace+类名严格一致**;多类一文件仅允许非独立加载的辅助结构,独立公共类一律一文件一类;
- 设计模式落地时先画三层图(谁依赖谁/骨架在哪/差异在哪),确认结构完整再写类;类名与模式语义对得上才允许用模式命名。
