# 开发者示例(examples/)

> 每个示例独立可运行,按层递进:core → functional → plugin。运行前先 `composer install`。
> 统一用 PHP 7.4:`/usr/local/bin/php74 examples/<name>/run.php`

| 示例 | 层 | 内容 | 依赖 |
| --- | --- | --- | --- |
| [httpbin-quick](httpbin-quick/run.php) | core | 最短路径四形态:GET/POST JSON/配置派生(wither)/传输异常 | 公网 httpbin.org |
| [scenario](scenario/run.php) | functional | 场景脚本解析→Runner 执行→报告与终态变量→CLI 等价命令 | 离线可跑(演示脚本指向不可达地址,展示失败/skip/extract 兜底) |
| [openai-chat](openai-chat/run.php) | plugin+functional | OpenAI 协议兼容 Chat:单轮/多轮/用量提取/错误形态;兼容端点(千帆等)只换 base-url | 需 api-key(config.php 或环境变量) |

## OpenAI 示例配置

```bash
cd examples/openai-chat
cp config.example.php config.php   # config.php 在 .gitignore 中,不会入库
# 编辑 config.php,或使用环境变量:
WS_HTTP_OPENAI_BASE_URL=https://qianfan.baidubce.com/v2/tokenplan/team \
WS_HTTP_OPENAI_KEY=bce-v3/xxxx \
WS_HTTP_OPENAI_MODEL=glm-5.3-flash \
/usr/local/bin/php74 run.php
```

任何 OpenAI 协议兼容服务(OpenAI 官方 / 千帆 / DeepSeek / vLLM 自建)都通过 `Client($apiKey, null, $baseUrl)` 的第三个参数接入——plugin 层只封装认证与端点,协议不变。

## 示例编写约定

1. 一个目录一个示例,入口固定 `run.php`,头部 docblock 说明"演示什么/怎么跑";
2. 不写测试逻辑(那是 tests/ 的事),输出面向人的可读结果;
3. 涉及密钥的示例:配置一律 `config.example.php` 模板 + `config.php` 本地文件(gitignore)+ 环境变量三层兜底;
4. 示例要能在"无外部依赖"与"有外部依赖"间明确区分,离线可跑的优先。
