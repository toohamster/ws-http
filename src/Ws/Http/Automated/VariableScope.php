<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

/**
 * 变量作用域(design/15 §2):场景内变量的读写与快照。
 */
final class VariableScope
{
    /** @var array<string, VarValue> */
    private $vars = [];

    /** @var array<string, true> secret 变量名集合(design/15 §2.1.1,报告脱敏用) */
    private $secrets = [];

    /**
     * @param array<int, array{name: string, value: mixed, secret?: bool}> $variables
     */
    public function __construct(array $variables = [])
    {
        foreach ($variables as $variable) {
            $this->assertName((string) $variable['name']);
            if (isset($this->vars[$variable['name']])) {
                throw new \InvalidArgumentException(sprintf('Duplicate variable name: %s', $variable['name']));
            }
            $this->vars[$variable['name']] = new VarValue(true, $variable['value']);
            if (!empty($variable['secret'])) {
                $this->secrets[$variable['name']] = true;
            }
        }
    }

    public function get(string $name): VarValue
    {
        return $this->vars[$name] ?? VarValue::undefined();
    }

    /**
     * 已声明 → 更新(secret 标记按变量名保持,design/15 §2.1.1);未声明 → 追加。
     *
     * @param mixed $value
     */
    public function set(string $name, $value): void
    {
        $this->assertName($name);
        $this->vars[$name] = new VarValue(true, $value);
    }

    /**
     * 标记变量为敏感(运行期追加场景)。
     */
    public function markSecret(string $name): void
    {
        $this->secrets[$name] = true;
    }

    public function isSecret(string $name): bool
    {
        return isset($this->secrets[$name]);
    }

    /**
     * secret 变量名列表(Report 脱敏用)。
     *
     * @return string[]
     */
    public function secrets(): array
    {
        return array_keys($this->secrets);
    }

    public function has(string $name): bool
    {
        return isset($this->vars[$name]);
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->vars);
    }

    /**
     * 终态快照(报告用,design/15 §5)。
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->vars as $name => $varValue) {
            $out[$name] = $varValue->value;
        }

        return $out;
    }

    private function assertName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Invalid variable name "%s": must match [A-Za-z_][A-Za-z0-9_]*', $name)
            );
        }
    }
}
