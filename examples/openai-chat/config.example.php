<?php

/**
 * 本地配置示例:复制本文件为 config.php 并填入真实值(config.php 已进 .gitignore)。
 *
 * 兼容 OpenAI 协议的任意服务均可(OpenAI / 千帆 / DeepSeek / vLLM 自建等):
 *   base-url 形如 https://api.openai.com/v1 或 https://qianfan.baidubce.com/v2/tokenplan/team
 *   api-key  服务方颁发的密钥
 *   model    模型名(如 gpt-4o-mini / glm-5.3-flash)
 */

return [
    'base-url' => getenv('WS_HTTP_OPENAI_BASE_URL') ?: 'https://api.openai.com/v1',
    'api-key'  => getenv('WS_HTTP_OPENAI_KEY') ?: 'sk-your-key-here',
    'model'    => getenv('WS_HTTP_OPENAI_MODEL') ?: 'gpt-3.5-turbo',
];
