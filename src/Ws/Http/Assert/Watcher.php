<?php

declare(strict_types=1);

namespace Ws\Http\Assert;

use Ws\Http\Response;

/**
 * 流式断言门面(design/12 §4):链式调用,失败即抛 AssertionException。
 *
 * 与 AssertionRunner 共享核心实现;差异仅在失败行为(抛 vs 收集)。
 */
final class Watcher
{
    /** @var Response */
    private $response;

    /** @var AssertionRunner */
    private $runner;

    /** @var AssertionResult[] 收集的结果(collectResults 用) */
    private $collected = [];

    /** @var bool 收集模式:失败记录不抛(collect() 期间为 true) */
    private $collectMode = false;

    public function __construct(Response $response)
    {
        $this->response = $response;
        $this->runner = new AssertionRunner();
    }

    public static function create(Response $response): self
    {
        return new self($response);
    }

    public function setResponse(Response $response): self
    {
        $this->response = $response;

        return $this;
    }

    // ---------- 流式断言(design/12 §4.1) ----------

    public function assertStatusCode(int $statusCode): self
    {
        return $this->must('status', '', 'eq', $statusCode, 301);
    }

    public function assertTotalTimeLessThan(float $seconds): self
    {
        return $this->must('time', '', 'le', $seconds, 305);
    }

    /**
     * @param string[] $names
     */
    public function assertHeadersExist(array $names): self
    {
        foreach ($names as $name) {
            $this->must('header', (string) $name, 'not_null', null, 302);
        }

        return $this;
    }

    /**
     * 关联数组 = 精确匹配;索引数组退化为 assertHeadersExist。
     *
     * @param array<string|int, string|string[]> $headers
     */
    public function assertHeaders(array $headers): self
    {
        $isAssoc = $headers !== [] && array_keys($headers) !== range(0, \count($headers) - 1);

        if (!$isAssoc) {
            return $this->assertHeadersExist(array_map('strval', array_values($headers)));
        }

        foreach ($headers as $name => $expected) {
            $this->must('header', (string) $name, 'header_value', $expected, 303);
        }

        return $this;
    }

    /**
     * 特殊值 IS_EMPTY / IS_VALID_JSON;否则包含匹配(修复 B1);$isRegex 时正则。
     */
    public function assertBody(string $expected, bool $isRegex = false): self
    {
        if ($isRegex) {
            return $this->must('raw_body', '', 'matches', $expected, 304);
        }

        if ($expected === 'IS_EMPTY') {
            return $this->must('raw_body', '', 'raw_empty', null, 304);
        }

        if ($expected === 'IS_VALID_JSON') {
            return $this->must('raw_body', '', 'raw_valid_json', null, 304);
        }

        return $this->must('raw_body', '', 'contains', $expected, 304);
    }

    /**
     * JSON 宽松比较;失败消息可附双方详情。
     *
     * @param mixed $expected
     */
    public function assertBodyJson($expected, bool $showDetail = false): self
    {
        return $this->must('json_root', '', 'body_json', $expected, 305, $showDetail);
    }

    /**
     * 与期望 JSON 文件比对(JSON_PRETTY_PRINT 规范化,替换手写 prettyPrint/修复 B7)。
     */
    public function assertBodyJsonFile(string $file, bool $showDetail = false): self
    {
        if (!is_file($file)) {
            throw new AssertionException(
                sprintf("Assertion failed [json_file]\n  expected: file %s\n  actual:   file not found", $file),
                305
            );
        }

        $raw = file_get_contents($file);
        $decoded = json_decode((string) $raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AssertionException(
                sprintf("Assertion failed [json_file]\n  expected: valid JSON in %s\n  actual:   %s", $file, json_last_error_msg()),
                305
            );
        }

        return $this->assertBodyJson($decoded, $showDetail);
    }

    /**
     * JSONPath + 操作符断言(新增,引擎/高级用法)。
     *
     * @param mixed $expected
     */
    public function assertJsonPath(string $path, string $op, $expected): self
    {
        return $this->must('json', $path, $op, $expected, 305);
    }

    /**
     * 收集模式:捕获后续断言失败为结果,不抛(供引擎/测试复用,design/12 §4 collect)。
     *
     * @param callable(Watcher): mixed $assertions
     * @return AssertionResult[]
     */
    public function collect(callable $assertions): array
    {
        $before = \count($this->collected);
        $previousMode = $this->collectMode;
        $this->collectMode = true;

        try {
            $assertions($this);
        } finally {
            $this->collectMode = $previousMode;
        }

        return \array_slice($this->collected, $before);
    }

    /**
     * 取已收集的全部结果(collect 之后)。
     *
     * @return AssertionResult[]
     */
    public function collectResults(): array
    {
        return $this->collected;
    }

    // ---------- 内部 ----------

    /**
     * 执行一条断言:通过返回 $this;失败抛 AssertionException 或记录(collect 模式)。
     *
     * @param mixed $expected
     */
    private function must(string $source, string $path, string $op, $expected, int $errorCode, bool $showDetail = false): self
    {
        $assertion = new Assertion($source, $path, $op, $expected);
        $result = $this->runner->runOnePublic($this->response, $assertion);
        $this->collected[] = $result;

        if (!$result->passed) {
            // 收集模式:记录不抛(design/12 §4 collect)
            if ($this->collectMode) {
                return $this;
            }

            $message = $result->message ?? 'Assertion failed';
            if ($showDetail) {
                $message .= "\n  expected(detail): " . var_export($expected, true)
                    . "\n  actual(detail):   " . var_export($result->actual, true);
            }
            throw new AssertionException($message, $errorCode);
        }

        return $this;
    }
}
