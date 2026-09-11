# 调试与根因分析教训(按真实案例归档)

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

## 案例 2:http_build_query 的 [] 编码是标准行为(S4)

`http_build_query(['a'=>['b'=>'c']])` 产出 `a%5Bb%5D=c` —— `[`/`]` 按 RFC1738 编码,**不是 bug**。设计文档说"拍平为 a[b]=c"指的是键结构;服务端解码后即还原。测试断言用 `%5B%5D` 形态。

## 案例 3:不要锁死 json_last_error() 的具体 errno(S4)

`json_decode('{"broken:')` 实测 errno=3(CTRL_CHAR)而非 4(SYNTAX)—— 具体错误码随截断形态/PHP 版本变化。测试断言用 `assertNotSame(JSON_ERROR_NONE, ...)` + 错误消息非空。

## 案例 4:buildHttpQuery 递归条件遗漏普通对象(S4)

条件写成 `is_array($v) || ($v instanceof Traversable)` 会把 stdClass 当标量跳过 —— stdClass 不实现 Traversable。正确:`is_array($v) || is_object($v)`(CURLFile 提前分支排除),对象经 get_object_vars 转数组再递归。

## 方法论:批量分析再动手(用户明确要求)

测试失败时**不许"改一行跑一次"循环**:先全量读失败详情,在纸面推演每个失败的根因与层级(测试预期错 vs 实现错 vs 环境事实),列出修正清单给用户确认,然后一次改完。本案例中 4 个失败里 3 个是不同性质的根因,混在一条循环里会掩盖真 bug(案例 1 被我误修了两次下游)。
