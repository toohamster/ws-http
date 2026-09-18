<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Expression\ExpressionEvaluator;
use Ws\Http\Method;
use Ws\Http\StrKit;

/**
 * 场景脚本解析器(design/14 §5:校验规则 V1–V13)。
 *
 * 加载期一次性完成全部校验;失败抛 AutomatedException(code 401/402),消息含 JSON Pointer。
 * 已知取舍:V10(跨步骤变量引用)保守策略——加载期不校验,运行期按 design/15 §4.3 处理。
 * V11–V13(design/22 §2):pause 步骤 var/from 必填、options 结构校验;
 * from 引用的策略是否存在由 Runner 装配期校验(PauseRegistry,407),解析器不持有注册表。
 */
final class ScenarioParser
{
    /** @var ExpressionEvaluator|null V9 预校验用 */
    private $evaluator;

    public function __construct(?ExpressionEvaluator $evaluator = null)
    {
        $this->evaluator = $evaluator;
    }

    /**
     * 解析 JSON 字符串 → Scenario。
     */
    public function parse(string $json): Scenario
    {
        $data = json_decode($json, true);

        if (!\is_array($data)) {
            throw new AutomatedException(
                sprintf('Invalid scenario JSON: %s', json_last_error_msg()),
                401
            );
        }

        return $this->parseArray($data);
    }

    /**
     * 从文件解析。
     */
    public function parseFile(string $path): Scenario
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new AutomatedException(sprintf('Cannot read scenario file: %s', $path), 401);
        }

        return $this->parse($raw);
    }

    /**
     * 解析关联数组(测试友好)。
     *
     * @param array<string, mixed> $data
     */
    public function parseArray(array $data): Scenario
    {
        $this->checkFields($data, '$', ['id', 'name', 'description', 'settings', 'variables', 'steps', 'datasets', '$schema']);

        // V2:顶层必填
        foreach (['id', 'name', 'steps'] as $required) {
            if (!isset($data[$required]) || $data[$required] === '') {
                throw $this->error('$', sprintf('missing required field "%s"', $required));
            }
        }
        if (!\is_array($data['steps']) || $data['steps'] === []) {
            throw $this->error('/steps', 'must be a non-empty array');
        }

        $scenario = new Scenario();
        $scenario->id = (string) $data['id'];
        $scenario->name = (string) $data['name'];
        $scenario->description = isset($data['description']) ? (string) $data['description'] : '';

        // settings(V3 未知字段拒)
        if (isset($data['settings'])) {
            if (!\is_array($data['settings'])) {
                throw $this->error('/settings', 'must be an object');
            }
            $this->checkFields($data['settings'], '/settings', ['timeout', 'failFast', 'cookieStore', 'proxy', 'redirect', 'iterate']);
            if (isset($data['settings']['redirect'])) {
                $data['settings']['redirect'] = $this->parseRedirect($data['settings']['redirect'], '/settings/redirect');
            }
            $scenario->settings = array_merge($scenario->settings, $data['settings']);
            $scenario->settings['failFast'] = (bool) $scenario->settings['failFast'];
        }

        // variables(V8:命名 + 唯一 + secret bool)
        $scenario->variables = $this->parseVariables($data['variables'] ?? []);

        // datasets(V14:名 → 内联数组或 {file|loader} 对象)
        $datasets = $this->parseDatasets($data['datasets'] ?? null);
        if ($datasets !== []) {
            $scenario->datasets = $datasets;
        }

        // V15:iterate 引用的数据集必须存在
        if (isset($data['settings']['iterate'])) {
            $iterate = (string) $data['settings']['iterate'];
            if ($iterate === '') {
                throw $this->error('/settings/iterate', 'must be a non-empty string');
            }
            if (!isset($scenario->datasets[$iterate])) {
                throw $this->error('/settings/iterate', sprintf('references unknown dataset "%s"', $iterate));
            }
        }

        // steps(V4 id 唯一 / V5 method×mode / V6 时间 / V7 auth+proxy / V9 表达式 / V11 template)
        $scenario->steps = $this->parseSteps($data['steps']);

        return $scenario;
    }

    // ---------- variables ----------

    /**
     * @return array<int, array{name: string, value: mixed, secret?: bool}>
     */
    private function parseVariables($raw): array
    {
        if ($raw === []) {
            return [];
        }
        if (!\is_array($raw)) {
            throw $this->error('/variables', 'must be an array');
        }

        $variables = [];
        foreach ($raw as $i => $item) {
            $pointer = sprintf('/variables/%d', $i);
            if (!\is_array($item)) {
                throw $this->error($pointer, 'must be an object');
            }
            $this->checkFields($item, $pointer, ['name', 'value', 'secret']);

            $name = (string) ($item['name'] ?? '');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw $this->error($pointer . '/name', sprintf('"%s" does not match [A-Za-z_][A-Za-z0-9_]*', $name));
            }
            if (isset($variables[$name])) {
                throw $this->error($pointer . '/name', sprintf('duplicate variable name "%s"', $name));
            }

            $entry = ['name' => $name, 'value' => $item['value'] ?? null];
            if (isset($item['secret'])) {
                if (!\is_bool($item['secret'])) {
                    throw $this->error($pointer . '/secret', 'must be a boolean');
                }
                $entry['secret'] = $item['secret'];
            }
            $variables[$name] = $entry;
        }

        return array_values($variables);
    }

    // ---------- steps ----------

    /**
     * @return array<int, HttpStep|DelayStep>
     */
    private function parseSteps(array $rawSteps): array
    {
        $steps = [];
        $seenIds = [];

        foreach ($rawSteps as $i => $raw) {
            $pointer = sprintf('/steps/%d', $i);
            if (!\is_array($raw)) {
                throw $this->error($pointer, 'must be an object');
            }

            $type = (string) ($raw['type'] ?? '');
            if (!\in_array($type, ['http', 'delay', 'pause'], true)) {
                throw $this->error($pointer . '/type', sprintf('"%s" is not a valid type (http|delay|pause)', $type));
            }

            $this->checkFields(
                $raw,
                $pointer,
                ['type', 'id', 'name', 'url', 'method', 'timeout', 'headers', 'auth', 'proxy', 'body', 'extract', 'assertions', 'duration', 'var', 'from', 'options', 'prompt', 'redirect']
            );

            $id = (string) ($raw['id'] ?? '');
            if ($id === '') {
                throw $this->error($pointer . '/id', 'missing required field "id"');
            }
            if (isset($seenIds[$id])) {
                throw $this->error($pointer . '/id', sprintf('duplicate step id "%s"', $id));
            }
            $seenIds[$id] = true;

            $name = (string) ($raw['name'] ?? '');
            if ($name === '') {
                throw $this->error($pointer . '/name', 'missing required field "name"');
            }

            if ($type === 'delay') {
                $steps[] = new DelayStep($id, $name, $this->parseDuration($raw['duration'] ?? null, $pointer . '/duration'));
                continue;
            }

            if ($type === 'pause') {
                $steps[] = $this->parsePauseStep($raw, $pointer, $id, $name);
                continue;
            }

            $steps[] = $this->parseHttpStep($raw, $pointer, $id, $name);
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function parseHttpStep(array $raw, string $pointer, string $id, string $name): HttpStep
    {
        foreach (['url', 'method'] as $required) {
            if (!isset($raw[$required]) || (string) $raw[$required] === '') {
                throw $this->error($pointer . '/' . $required, sprintf('missing required field "%s"', $required));
            }
        }

        $method = strtoupper((string) $raw['method']);
        if (!\in_array($method, $this->allMethods(), true)) {
            throw $this->error($pointer . '/method', sprintf('"%s" is not a valid HTTP method', $raw['method']));
        }

        // headers(V3)
        $headers = [];
        if (isset($raw['headers'])) {
            if (!\is_array($raw['headers'])) {
                throw $this->error($pointer . '/headers', 'must be an object');
            }
            foreach ($raw['headers'] as $hName => $hValue) {
                if (!\is_string($hValue)) {
                    throw $this->error($pointer . '/headers/' . $hName, 'header value must be a string');
                }
                $headers[(string) $hName] = $hValue;
            }
        }

        // V5:method × body.mode 合法矩阵
        $body = null;
        if (isset($raw['body'])) {
            $body = $this->parseBody($raw['body'], $pointer . '/body', $method);
        }
        if (!$this->bodyAllowed($method) && $body !== null) {
            throw $this->error($pointer . '/body', sprintf('%s does not accept a body', $method));
        }

        // V6:timeout
        $timeout = isset($raw['timeout']) ? $this->parseTimeout($raw['timeout'], $pointer . '/timeout') : null;

        // V7:auth
        $auth = isset($raw['auth']) ? $this->parseAuth($raw['auth'], $pointer . '/auth') : null;

        // V7:proxy
        $proxy = isset($raw['proxy']) ? $this->parseProxy($raw['proxy'], $pointer . '/proxy') : null;

        // extract + V9/V11
        $extract = [];
        if (isset($raw['extract'])) {
            if (!\is_array($raw['extract'])) {
                throw $this->error($pointer . '/extract', 'must be an array');
            }
            $extract = $this->parseExtract($raw['extract'], $pointer . '/extract');
        }

        // assertions + V9
        $assertions = [];
        if (isset($raw['assertions'])) {
            if (!\is_array($raw['assertions'])) {
                throw $this->error($pointer . '/assertions', 'must be an array');
            }
            $assertions = $this->parseAssertions($raw['assertions'], $pointer . '/assertions');
        }

        return new HttpStep($id, $name, (string) $raw['url'], $method, $headers, $auth, $proxy, $body, $extract, $assertions, $timeout, isset($raw['redirect']) ? $this->parseRedirect($raw['redirect'], $pointer . '/redirect') : null);
    }

    /**
     * V11–V13:pause 步骤(var/from 必填,options 对象,extract 规则复用)。
     *
     * @param array<string, mixed> $raw
     */
    private function parsePauseStep(array $raw, string $pointer, string $id, string $name): PauseStep
    {
        // V11:var/from 必填
        foreach (['var', 'from'] as $required) {
            if (!isset($raw[$required]) || (string) $raw[$required] === '') {
                throw $this->error($pointer . '/' . $required, sprintf('pause step requires field "%s"', $required));
            }
        }

        $var = (string) $raw['var'];
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var)) {
            throw $this->error($pointer . '/var', sprintf('"%s" does not match variable naming rule', $var));
        }

        // V13:options 结构校验
        $options = $raw['options'] ?? [];
        if (!\is_array($options)) {
            throw $this->error($pointer . '/options', 'must be an object');
        }
        $this->checkOptionsScalarMap($options, $pointer . '/options');

        $prompt = isset($raw['prompt']) ? (string) $raw['prompt'] : '';

        // extract(可选,复用 http extract 规则解析)
        $extract = [];
        if (isset($raw['extract'])) {
            if (!\is_array($raw['extract'])) {
                throw $this->error($pointer . '/extract', 'must be an array');
            }
            $extract = $this->parseExtract($raw['extract'], $pointer . '/extract');
        }

        return new PauseStep($id, $name, $var, (string) $raw['from'], $options, $prompt, $extract);
    }

    /**
     * V14:datasets 必须是对象;每个值是"记录数组"或含 file/loader 的对象。
     * V16:非空且所有记录为 object(同构校验在 DatasetRunner/CLI 装配时做——解析器只保形态)。
     *
     * @return array<string, mixed>
     */
    private function parseDatasets($raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw) || $raw === array_values($raw)) { // array_values 比对 = 纯列表判定(7.4 无 array_is_list)
            throw $this->error('/datasets', 'must be an object (name -> records)');
        }

        $datasets = [];
        foreach ($raw as $name => $spec) {
            $pointer = sprintf('/datasets/%s', $name);
            if (!\is_array($spec)) {
                throw $this->error($pointer, 'must be an array (inline records) or an object (file/loader)');
            }
            $datasets[(string) $name] = $spec;
        }

        return $datasets;
    }

    /**
     * V18:redirect 结构(follow bool / max int ≥0;follow:false + 显式 max 拒绝)。
     *
     * @return array{follow: bool, max: int}
     */
    private function parseRedirect($raw, string $pointer): array
    {
        if (!\is_array($raw)) {
            throw $this->error($pointer, 'must be an object');
        }
        $this->checkFields($raw, $pointer, ['follow', 'max']);

        $follow = isset($raw['follow']) ? $raw['follow'] : true;
        if (!\is_bool($follow)) {
            throw $this->error($pointer . '/follow', 'must be a boolean');
        }

        if (isset($raw['max'])) {
            $max = $raw['max'];
            if (!\is_int($max) || $max < 0) {
                throw $this->error($pointer . '/max', 'must be an integer >= 0');
            }
            if (!$follow) {
                throw $this->error($pointer . '/max', 'is meaningless when follow=false (remove max or set follow=true)');
            }
        }

        return ['follow' => $follow, 'max' => isset($raw['max']) ? $raw['max'] : 10];
    }

    /**
     * V13:options 只允许标量值的一层对象(poll 的 interval/timeout、stdin 的 message/mask、
     * environment 的 env/file、poll 探测的 path/response/rawBody 均为标量/数组形态)。
     *
     * @param array<string, mixed> $options
     */
    private function checkOptionsScalarMap(array $options, string $pointer): void
    {
        foreach ($options as $key => $value) {
            if (\is_array($value)) {
                // 允许标量列表(如 response 数组形态),不允许嵌套对象键
                foreach ($value as $k => $v) {
                    if (!\is_int($k) || \is_array($v)) {
                        throw $this->error($pointer . '/' . $key, 'must be a scalar value or a list of scalars');
                    }
                }
                continue;
            }
            if ($value !== null && !\is_scalar($value)) {
                throw $this->error($pointer . '/' . $key, 'must be a scalar value');
            }
        }
    }

    /**
     * @return array{mode: string, content: mixed}
     */
    private function parseBody($raw, string $pointer, string $method): array
    {
        if (!\is_array($raw)) {
            throw $this->error($pointer, 'must be an object');
        }
        $this->checkFields($raw, $pointer, ['mode', 'content']);

        $mode = (string) ($raw['mode'] ?? '');
        $validModes = ['params', 'urlencoded', 'json', 'xml', 'html', 'text'];

        if (!\in_array($mode, $validModes, true)) {
            throw $this->error($pointer . '/mode', sprintf('"%s" is not a valid mode (%s)', $mode, implode('|', $validModes)));
        }

        // V5:GET/HEAD 仅 params
        if (!$this->bodyAllowed($method) && $mode !== 'params') {
            throw $this->error($pointer . '/mode', sprintf('"%s" only allows mode=params', $method));
        }

        if (!isset($raw['content'])) {
            throw $this->error($pointer . '/content', 'missing required field "content"');
        }

        return ['mode' => $mode, 'content' => $raw['content']];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAuth($raw, string $pointer): array
    {
        if (!\is_array($raw)) {
            throw $this->error($pointer, 'must be an object');
        }
        $this->checkFields($raw, $pointer, ['type', 'user', 'password', 'token', 'value']);

        $type = (string) ($raw['type'] ?? '');
        if (!\in_array($type, ['basic', 'bearer', 'header'], true)) {
            throw $this->error($pointer . '/type', sprintf('"%s" is not a valid auth type (basic|bearer|header)', $type));
        }

        foreach ($raw as $key => $value) {
            if (\in_array($key, ['user', 'password', 'token', 'value'], true) && !\is_string($value)) {
                throw $this->error($pointer . '/' . $key, 'must be a string');
            }
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseProxy($raw, string $pointer): array
    {
        if (!\is_array($raw)) {
            throw $this->error($pointer, 'must be an object');
        }
        $this->checkFields($raw, $pointer, ['type', 'address', 'port', 'tunnel', 'auth']);

        $validTypes = ['http', 'http1.0', 'socks4', 'socks4a', 'socks5', 'socks5.hostname'];
        $type = (string) ($raw['type'] ?? '');
        if (!\in_array($type, $validTypes, true)) {
            throw $this->error($pointer . '/type', sprintf('"%s" is not a valid proxy type', $type));
        }

        if (isset($raw['address']) && !\is_string($raw['address'])) {
            throw $this->error($pointer . '/address', 'must be a string');
        }
        if (isset($raw['tunnel']) && !\is_bool($raw['tunnel'])) {
            throw $this->error($pointer . '/tunnel', 'must be a boolean');
        }

        if (isset($raw['port'])) {
            $port = $raw['port'];
            if (!\is_int($port) || $port < 1 || $port > 65535) {
                throw $this->error($pointer . '/port', 'must be an integer in [1, 65535]');
            }
        }

        if (isset($raw['auth'])) {
            if (!\is_array($raw['auth'])) {
                throw $this->error($pointer . '/auth', 'must be an object');
            }
            $this->checkFields($raw['auth'], $pointer . '/auth', ['user', 'password']);
        }

        return $raw;
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array<string, mixed>>
     */
    private function parseExtract(array $raw, string $pointer): array
    {
        $validSources = ['json', 'header', 'raw_body', 'status', 'time', 'template'];
        $rules = [];

        foreach ($raw as $i => $item) {
            $itemPointer = sprintf('%s/%d', $pointer, $i);
            if (!\is_array($item)) {
                throw $this->error($itemPointer, 'must be an object');
            }
            $this->checkFields($item, $itemPointer, ['var', 'source', 'path', 'multiple', 'onMissing', 'defaultValue']);

            $source = (string) ($item['source'] ?? '');
            if (!\in_array($source, $validSources, true)) {
                throw $this->error($itemPointer . '/source', sprintf('"%s" is not a valid source (%s)', $source, implode('|', $validSources)));
            }

            $var = (string) ($item['var'] ?? '');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var)) {
                throw $this->error($itemPointer . '/var', sprintf('"%s" does not match variable naming rule', $var));
            }

            if ($source === 'json') {
                $path = (string) ($item['path'] ?? '');
                try {
                    ($this->evaluator ?? new ExpressionEvaluator())->validate($path);
                } catch (\Throwable $e) {
                    throw $this->error($itemPointer . '/path', sprintf('invalid JSONPath: %s', $path));
                }
            }

            // V11:template 模式串必须含 {name}
            if ($source === 'template') {
                $pattern = (string) ($item['path'] ?? '');
                if (preg_match_all('/\{(\w+)\}/', $pattern) === 0) {
                    throw $this->error($itemPointer . '/path', 'template pattern must contain at least one {name} placeholder');
                }
            }

            $rules[] = $item;
        }

        return $rules;
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array<string, mixed>>
     */
    private function parseAssertions(array $raw, string $pointer): array
    {
        $validSources = ['status', 'json', 'header', 'raw_body', 'time'];
        $rules = [];

        foreach ($raw as $i => $item) {
            $itemPointer = sprintf('%s/%d', $pointer, $i);
            if (!\is_array($item)) {
                throw $this->error($itemPointer, 'must be an object');
            }
            $this->checkFields($item, $itemPointer, ['source', 'path', 'op', 'expected', 'name']);

            $source = (string) ($item['source'] ?? '');
            if (!\in_array($source, $validSources, true)) {
                throw $this->error($itemPointer . '/source', sprintf('"%s" is not a valid assertion source', $source));
            }

            if ($source === 'json') {
                $path = (string) ($item['path'] ?? '');
                try {
                    ($this->evaluator ?? new ExpressionEvaluator())->validate($path);
                } catch (\Throwable $e) {
                    throw $this->error($itemPointer . '/path', sprintf('invalid JSONPath: %s', $path));
                }
            }

            $rules[] = $item;
        }

        return $rules;
    }

    // ---------- 工具 ----------

    /**
     * V6:时间字符串归一为秒(float);timeout 场景。
     */
    private function parseTimeout($raw, string $pointer): string
    {
        // timeout 保持字符串形态(Runner 归一);仅做格式预检
        if (!\is_string($raw) || preg_match('/^\d+(\.\d+)?(ms|s|m)?$/', $raw) !== 1) {
            throw $this->error($pointer, sprintf('"%s" is not a valid duration (e.g. "15s", "500ms", "2m")', var_export($raw, true)));
        }

        return $raw;
    }

    /**
     * V6:时间字符串 → 秒。
     */
    private function parseDuration($raw, string $pointer): float
    {
        if (!\is_string($raw) || preg_match('/^(\d+(?:\.\d+)?)(ms|s|m)?$/', $raw, $m) !== 1) {
            throw $this->error($pointer, sprintf('"%s" is not a valid duration', var_export($raw, true)));
        }

        $value = (float) $m[1];
        $unit = $m[2] ?? 's';

        if ($unit === 'ms') {
            return $value / 1000;
        }
        if ($unit === 'm') {
            return $value * 60;
        }

        return $value;
    }

    /**
     * V3:未知字段拒绝(严格模式)。
     *
     * @param array<string, mixed> $data
     * @param string[] $allowed
     */
    private function checkFields(array $data, string $pointer, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (!\in_array((string) $key, $allowed, true)) {
                throw $this->error($pointer . '/' . $key, 'unknown field (strict mode)');
            }
        }
    }

    private function bodyAllowed(string $method): bool
    {
        return !\in_array($method, Method::NO_BODY_METHODS, true);
    }

    /**
     * @return string[]
     */
    private function allMethods(): array
    {
        static $methods = null;
        if ($methods === null) {
            $ref = new \ReflectionClass(Method::class);
            $methods = array_values($ref->getConstants());
        }

        return $methods;
    }

    private function error(string $pointer, string $reason): AutomatedException
    {
        return new AutomatedException(sprintf('%s: %s', $pointer, $reason), 402);
    }
}
