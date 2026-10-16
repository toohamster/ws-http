<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt\Transport;

/**
 * 泛用 OpenAI 兼容传输(逃生门,design/28 §5 补充事实):冷门 OpenAI 兼容服务
 * 不必新建厂商类——baseUrl/default_model 经 config 注入即用。
 *
 * config:api_key(必填)/ base_url(必填,服务端点)/ default_model(可选,
 * settings.model 未选时的兜底)。
 */
final class GenericOpenAiTransport extends OpenAiCompatibleTransport
{
    /** @var string config 注入的默认模型(差异槽常量的实例级替代) */
    private $configDefaultModel = '';

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->configDefaultModel = (string) ($config['default_model'] ?? '');
    }

    protected function defaultModel(): string
    {
        return $this->configDefaultModel !== '' ? $this->configDefaultModel : parent::defaultModel();
    }
}
