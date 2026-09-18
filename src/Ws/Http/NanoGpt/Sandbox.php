<?php

declare(strict_types=1);

namespace Ws\Http\NanoGpt;

/**
 * 沙箱(design/21 §5):本地文件工具的安全边界——穿越检查集中一处,工具自身不重复实现。
 *
 * 仅适用于本地文件系统(S3/远程介质的访问控制是另一套语义,应走自定义 Tool)。
 */
final class Sandbox
{
    /** @var string 锚定 root(绝对路径) */
    private $root;

    /** 构造时锁定 root;目录不存在则创建(mkdir 0755, recursive) */
    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false) {
            if (!@mkdir($root, 0755, true) && !is_dir($root)) {
                throw new AgentException(sprintf('Cannot create sandbox root "%s"', $root), 610);
            }
            $real = (string) realpath($root);
        }
        $this->root = rtrim($real, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * 用户路径 → root 内绝对路径。
     *
     * @throws AgentException 610 路径越界(解析后不在 root 内)
     */
    public function resolve(string $userPath): string
    {
        $candidate = $userPath === '' ? $this->root : (
            $userPath[0] === '/'
                ? $userPath
                : $this->root . '/' . $userPath
        );

        // 逐段解析:存在段经 realpath(符号链接语义天然覆盖:链接指向 root 外即被拒),
        // 末段不存在(写文件常态)→ 归一化 . / ..
        $resolved = $this->resolvePartial($candidate);

        if ($resolved !== $this->root && strpos($resolved, $this->root . '/') !== 0) {
            throw new AgentException(
                sprintf('Path "%s" resolves outside sandbox root "%s"', $userPath, $this->root),
                610
            );
        }

        return $resolved;
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
