<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

use Ws\Http\Request;

/**
 * 完整预设(design/21 §4.2):4 内建工具 + 模型目录来源注入。
 */
final class FullPreset implements Preset
{
    /** @var Sandbox */
    private $sandbox;

    /** @var ModelSourceInterface|null */
    private $modelSource;

    /** @var string[] HttpGetTool 域名白名单 */
    private $httpAllowHosts;

    /** @var Request|null */
    private $http;

    /**
     * @param string[] $httpAllowHosts
     */
    public function __construct(Sandbox $sandbox, ?ModelSourceInterface $modelSource = null, array $httpAllowHosts = [], ?Request $http = null)
    {
        $this->sandbox = $sandbox;
        $this->modelSource = $modelSource;
        $this->httpAllowHosts = $httpAllowHosts;
        $this->http = $http;
    }

    public function tools(): array
    {
        return [
            new Tool\ReadFileTool($this->sandbox),
            new Tool\WriteFileTool($this->sandbox),
            new Tool\ListDirTool($this->sandbox),
            new Tool\HttpGetTool($this->httpAllowHosts, $this->http),
        ];
    }

    public function modelSource(): ?ModelSourceInterface
    {
        return $this->modelSource;
    }
}
