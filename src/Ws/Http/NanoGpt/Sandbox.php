<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 沙箱(design/21 §5):本地文件工具的安全边界——穿越检查集中一处,工具自身不重复实现。
 *
 * 仅适用于本地文件系统(S3/远程介质的访问控制是另一套语义,应走自定义 Tool)。
 * 多 root(评审修订 2026-09-20):primary(.work 产出区)+ extra(.runtime 草稿区),
 * resolve() 对全部 root 做前缀校验——模型可跨两区工作(草稿区建脚本、执行、结果存档回产出区)。
 */
final class Sandbox
{
    /** @var string 主 root(绝对路径,产出区) */
    private $root;

    /** @var string[] 附加 root(绝对路径;草稿区等),常驻目录,构造时自动创建 */
    private $extraRoots;

    /**
     * @param string[] $extraRoots 附加 root(空值/相对路径项忽略)
     */
    public function __construct(string $root, array $extraRoots = [])
    {
        $this->root = $this->ensureRoot($root);
        $this->extraRoots = [];
        foreach ($extraRoots as $extra) {
            if (\is_string($extra) && $extra !== '') {
                $this->extraRoots[] = $this->ensureRoot($extra);
            }
        }
    }

    private function ensureRoot(string $root): string
    {
        $real = realpath($root);
        if ($real === false) {
            if (!@mkdir($root, 0755, true) && !is_dir($root)) {
                throw new AgentException(sprintf('Cannot create sandbox root "%s"', $root), 610);
            }
            $real = (string) realpath($root);
        }

        return rtrim($real, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return string[]
     */
    public function extraRoots(): array
    {
        return $this->extraRoots;
    }

    /**
     * 用户路径 → 某个 root 内的绝对路径。
     *
     * 相对路径对 primary root 解析(产出区是默认工作面);访问 extra root 须以
     * 其绝对路径或 primary 相对路径(映射到 primary)表达。
     *
     * @throws AgentException 610 路径越界(解析后不在任何 root 内)
     */
    public function resolve(string $userPath): string
    {
        $resolved = $this->resolveAgainst($userPath, $this->root, true);

        if ($this->isInside($resolved, $this->root)) {
            return $resolved;
        }

        foreach ($this->extraRoots as $extra) {
            if ($this->isInside($resolved, $extra)) {
                return $resolved;
            }
        }

        throw new AgentException(
            sprintf('Path "%s" resolves outside sandbox roots ("%s")', $userPath, implode(', ', array_merge([$this->root], $this->extraRoots))),
            610
        );
    }

    private function resolveAgainst(string $userPath, string $base, bool $absoluteAllowed): string
    {
        $candidate = $userPath === '' ? $base : (
            ($absoluteAllowed && $userPath[0] === '/')
                ? $userPath
                : $base . '/' . $userPath
        );

        // 逐段解析:存在段经 realpath(穿透符号链接),剩余不存在的尾部归一化——
        // 整串 realpath 在"链接目录下的不存在文件"上会误判为 root 内(链接段未被解析)。
        return $this->resolvePartial($candidate);
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    /**
     * 存在前缀经 realpath(穿透符号链接),剩余不存在的尾部归一化——
     * 整串 realpath 在"链接目录下的不存在文件"上会误判为 root 内(链接段未被解析)。
     */
    private function resolvePartial(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        $parent = dirname($path);
        if ($parent === $path) { // 到达根
            return $this->normalize($path);
        }

        return rtrim($this->resolvePartial($parent), '/') . '/' . basename($path);
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return '/' . implode('/', $parts);
    }
}
