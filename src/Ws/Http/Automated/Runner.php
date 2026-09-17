<?php

declare(strict_types=1);

namespace Ws\Http\Automated;

use Ws\Http\Assert\AssertionRunner;
use Ws\Http\Automated\Pause\PauseRegistry;
use Ws\Http\Automated\Pause\ValueResult;
use Ws\Http\Body;
use Ws\Http\Contract\CookieStoreInterface;
use Ws\Http\Contract\RequestFactoryInterface;
use Ws\Http\PreparedBody;
use Ws\Http\RequestOptions;
use Ws\Http\RequestException;
use Ws\Http\Response;
use Ws\Http\Support\ResultSet;

/**
 * 场景执行器(design/16 §1–2):resolve → 发送 → 提取 → 断言 → 判定。
 *
 * - 引擎内部不抛异常(除解析失败):一切失败记录进 StepResult;
 * - failFast:首个 failed 步骤后其余 skipped;
 * - 配置三级合并:内置默认 < 场景 settings < 步骤覆盖;
 * - secret 变量:输出层经 Redactor 脱敏(design/16 §3.3)。
 */
final class Runner
{
    /** @var RequestFactoryInterface */
    private $factory;

    /** @var ScenarioResolver */
    private $resolver;

    /** @var VarExtractor */
    private $extractor;

    /** @var AssertionRunner */
    private $assertionRunner;

    /** @var PauseRegistry|null pause 取值策略注册表(design/22;null = 场景不含 pause 步骤时零开销) */
    private $pauseRegistry;

    public function __construct(
        RequestFactoryInterface $factory,
        ?ScenarioResolver $resolver = null,
        ?VarExtractor $extractor = null,
        ?AssertionRunner $assertionRunner = null,
        ?PauseRegistry $pauseRegistry = null
    ) {
        $this->factory = $factory;
        $this->resolver = $resolver ?? new ScenarioResolver();
        $this->extractor = $extractor ?? new VarExtractor();
        $this->assertionRunner = $assertionRunner ?? new AssertionRunner();
        $this->pauseRegistry = $pauseRegistry;
    }

    public function run(Scenario $scenario): Report
    {
        $startedAt = microtime(true);

        $scope = new VariableScope($scenario->variables);
        $cookies = $this->createCookieStore((string) $scenario->settings['cookieStore']);

        $report = new Report($scenario->id, $scenario->name);
        $failFast = (bool) $scenario->settings['failFast'];
        $stopped = false;

        foreach ($scenario->steps as $step) {
            if ($stopped) {
                $report->add(StepResult::skipped($step->id(), $step->name(), sprintf("fail-fast after step '%s'", $this->lastFailedId($report))));
                continue;
            }

            $stepResult = $this->runStep($step, $scope, $cookies, $scenario);
            $report->add($stepResult);

            if ($failFast && $stepResult->status === StepResult::STATUS_FAILED) {
                $stopped = true;
            }
        }

        // 终态变量快照(脱敏:secret 值替换)
        $secretValues = [];
        foreach ($scope->secrets() as $secretName) {
            $value = $scope->get($secretName)->value;
            if (\is_string($value) && $value !== '') {
                $secretValues[] = $value;
            }
        }

        $report->finalize(
            Redactor::apply($scope->snapshot(), $secretValues),
            (microtime(true) - $startedAt) * 1000
        );

        return $report;
    }

    /**
     * 单步执行(公开供调试/单测)。
     */
    public function runStep(Step $step, VariableScope $scope, CookieStoreInterface $cookies, Scenario $scenario): StepResult
    {
        $startedAt = microtime(true);

        if ($step instanceof DelayStep) {
            \sleep((int) ceil($step->duration()));
            $stepResult = new StepResult($step->id(), $step->name(), $step->type(), StepResult::STATUS_PASSED);
            $stepResult->durationMs = (microtime(true) - $startedAt) * 1000;

            return $stepResult;
        }

        if ($step instanceof PauseStep) {
            return $this->runPauseStep($step, $scope, $startedAt);
        }

        \assert($step instanceof HttpStep);

        try {
            return $this->runHttpStep($step, $scope, $cookies, $scenario, $startedAt);
        } catch (\InvalidArgumentException $e) {
            // resolve 未定义变量 / 请求参数非法
            $stepResult = new StepResult($step->id(), $step->name(), $step->type(), StepResult::STATUS_FAILED, $e->getMessage());
            $stepResult->durationMs = (microtime(true) - $startedAt) * 1000;

            return $stepResult;
        } catch (RequestException $e) {
            // 传输层失败(连接/DNS/超时/SSL)
            $stepResult = new StepResult($step->id(), $step->name(), $step->type(), StepResult::STATUS_FAILED, $e->getMessage());
            $stepResult->durationMs = (microtime(true) - $startedAt) * 1000;

            return $stepResult;
        }
    }

    // ---------- http 步骤 ----------

    private function runHttpStep(HttpStep $step, VariableScope $scope, CookieStoreInterface $cookies, Scenario $scenario, float $startedAt): StepResult
    {
        // 1. ${var} 深替换(未定义变量抛 InvalidArgumentException → failed)
        $url = (string) $this->resolver->resolve($step->url(), $scope);
        $headers = $this->resolver->resolve($step->headers(), $scope);
        $auth = $step->auth() !== null ? $this->resolver->resolve($step->auth(), $scope) : null;
        $proxy = $step->proxy() !== null ? $this->resolver->resolve($step->proxy(), $scope) : null;
        $bodyRaw = $step->body() !== null ? $this->resolver->resolve($step->body()['content'], $scope) : null;
        $bodyMode = $step->body()['mode'] ?? null;

        // 2. 配置三级合并:内置 < 场景 settings.timeout < 步骤 timeout
        $timeoutString = $step->timeout() ?? (string) $scenario->settings['timeout'];
        $options = new RequestOptions();
        $options = $this->applyTimeout($options, $timeoutString);

        // auth → RequestOptions(design/14 §4.2)
        if (\is_array($auth)) {
            $options = $this->applyAuth($options, $auth);
        }

        // proxy → RequestOptions
        if (\is_array($proxy)) {
            $options = $options->withProxy(
                (string) ($proxy['address'] ?? ''),
                (int) ($proxy['port'] ?? 0),
                $this->proxyTypeConstant((string) ($proxy['type'] ?? 'http')),
                (bool) ($proxy['tunnel'] ?? false)
            );
            if (isset($proxy['auth']) && \is_array($proxy['auth'])) {
                $options = $options->withProxyAuth(
                    (string) ($proxy['auth']['user'] ?? ''),
                    (string) ($proxy['auth']['password'] ?? '')
                );
            }
        }

        // CookieStore:请求前注入
        $cookieHeader = $cookies->cookieHeaderFor($url);
        if ($cookieHeader !== null && !isset($headers['cookie'])) {
            $headers['cookie'] = $cookieHeader;
        }

        // 3. body mode → PreparedBody / 数组(design/14 §4.4)
        $payload = $this->buildPayload($bodyMode, $bodyRaw);

        // 4. 发送
        $request = $this->factory->create($options);
        $response = $request->send($step->method(), $url, $payload, $headers);

        // 5. CookieStore:响应后收集
        $cookies->collectFrom($response, $url);

        // 6. 提取(onMissing fail/skip/default)
        $extraction = $this->extractor->extract($response, $step->extract(), $scope);

        // 运行期 secret 标记注入(step 声明的 extractSecrets → scope,design/15 §2.1.1)
        foreach (array_keys($step->extractSecrets()) as $secretVar) {
            if ($scope->has($secretVar)) {
                $scope->markSecret($secretVar);
            }
        }

        // 7. 断言(全量,不短路)
        $assertions = AssertionRunner::fromArray($step->assertions());
        $assertionResultSet = $this->assertionRunner->run($response, $assertions);

        // 8. 判定:传输成功前提下,提取失败 ∨ 任一断言失败 → failed
        $status = StepResult::STATUS_PASSED;
        $failReason = null;

        if ($extraction->failures !== []) {
            $status = StepResult::STATUS_FAILED;
            $failReason = $extraction->failures[0]['message'];
        } elseif ($assertionResultSet->failed()->count() > 0) {
            $status = StepResult::STATUS_FAILED;
            $firstFailed = $assertionResultSet->failed()->all()[0];
            $failReason = $firstFailed->message ?? 'assertion failed';
        }

        // 输出脱敏:secret 变量值在 extractedVariables 中替换
        $secretValues = [];
        foreach ($scope->secrets() as $secretName) {
            $value = $scope->get($secretName)->value;
            if (\is_string($value) && $value !== '') {
                $secretValues[] = $value;
            }
        }

        $extracted = [];
        foreach ($extraction->written as $written) {
            $extracted[$written['var']] = $written['value'];
        }

        $stepResult = new StepResult(
            $step->id(),
            $step->name(),
            $step->type(),
            $status,
            $failReason,
            ['method' => $step->method(), 'url' => $url],
            $response->code,
            null,
            mb_substr($response->rawBody, 0, 2048),
            $assertionResultSet,
            $extraction->warnings,
            Redactor::apply($extracted, $secretValues)
        );
        $stepResult->durationMs = (microtime(true) - $startedAt) * 1000;

        return $stepResult;
    }

    // ---------- pause 步骤(design/22 §4) ----------

    private function runPauseStep(PauseStep $step, VariableScope $scope, float $startedAt): StepResult
    {
        $finish = function (StepResult $result) use ($startedAt): StepResult {
            $result->durationMs = (microtime(true) - $startedAt) * 1000;

            return $result;
        };

        if ($this->pauseRegistry === null) {
            return $finish(new StepResult(
                $step->id(),
                $step->name(),
                $step->type(),
                StepResult::STATUS_FAILED,
                'pause step used but no PauseRegistry configured on Runner'
            ));
        }

        // 未知名策略(装配期未校验到的兜底)→ 407 语义的步骤级失败
        if (!$this->pauseRegistry->has($step->from())) {
            return $finish(new StepResult(
                $step->id(),
                $step->name(),
                $step->type(),
                StepResult::STATUS_FAILED,
                sprintf('Unknown pause source "%s"', $step->from())
            ));
        }

        try {
            $result = $this->pauseRegistry->get($step->from())->fetch($step->options());
        } catch (\Ws\Http\Automated\AutomatedException $e) {
            return $finish(new StepResult($step->id(), $step->name(), $step->type(), StepResult::STATUS_FAILED, $e->getMessage()));
        } catch (\Throwable $e) {
            return $finish(new StepResult($step->id(), $step->name(), $step->type(), StepResult::STATUS_FAILED, sprintf('pause source failed: %s', $e->getMessage())));
        }

        if ($result->status() === ValueResult::MISSING) {
            return $finish(new StepResult($step->id(), $step->name(), $step->type(), StepResult::STATUS_FAILED, $result->reason() ?? 'pause source returned missing'));
        }

        // got:值写入 VariableScope(可选 extract 规则对结构化值二次提取)
        $value = $result->value();
        $written = [];
        $warnings = [];

        if ($step->extract() !== [] && \is_array($value)) {
            // 结构化值 → 伪 Response(带 JSON Content-Type)走既有提取器(json source 针对 body);extract 规则自带 var 名
            $json = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '{}';
            $probe = new \Ws\Http\Response(
                ['http_code' => 200, 'header_size' => 0, 'total_time' => 0.0],
                $json,
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n"
            );
            $extraction = $this->extractor->extract($probe, $step->extract(), $scope);
            $warnings = $extraction->warnings;
            if ($extraction->failures !== []) {
                return $finish(new StepResult($step->id(), $step->name(), $step->type(), StepResult::STATUS_FAILED, $extraction->failures[0]['message'], null, null, null, null, null, $extraction->warnings));
            }
            foreach ($extraction->written as $w) {
                $written[$w['var']] = $w['value'];
            }
            if (!isset($written[$step->var()])) {
                // 兜底:extract 未写到 var 名 → 整值回写
                $scope->set($step->var(), $value);
                $written[$step->var()] = $value;
            }
        } else {
            $scope->set($step->var(), $value);
            $written[$step->var()] = $value;
        }

        return $finish(new StepResult(
            $step->id(),
            $step->name(),
            $step->type(),
            StepResult::STATUS_PASSED,
            null,
            null,
            null,
            null,
            null,
            null,
            $warnings,
            $written,
            ['source' => $step->from(), 'var' => $step->var()]
        ));
    }

    // ---------- 组装辅助 ----------

    /**
     * @param mixed $content
     * @return mixed
     */
    private function buildPayload(?string $mode, $content)
    {
        if ($mode === null) {
            return null;
        }

        switch ($mode) {
            case 'urlencoded':
                return Body::form($content);

            case 'json':
                return Body::raw((string) $content, 'application/json');

            case 'xml':
                return Body::raw((string) $content, 'application/xml');

            case 'html':
                return Body::raw((string) $content, 'text/html');

            case 'text':
                return Body::raw((string) $content, 'text/plain');

            case 'params':
            default:
                // params:键值数组,cURL 自动 multipart 表单(design/14 §4.4)
                return \is_array($content) ? $content : null;
        }
    }

    private function applyTimeout(RequestOptions $options, string $timeoutString): RequestOptions
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(ms|s|m)?$/', $timeoutString, $m) !== 1) {
            return $options; // 非法回退默认(加载期已预检,此处防御)
        }

        $value = (float) $m[1];
        $unit = $m[2] ?? 's';

        if ($unit === 'ms') {
            $ms = (int) $value;
            return $ms < 1000 ? $options->withTimeoutMs($ms) : $options->withTimeout((int) ceil($value / 1000));
        }
        if ($unit === 'm') {
            return $options->withTimeout((int) ($value * 60));
        }

        return $options->withTimeout((int) ceil($value));
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function applyAuth(RequestOptions $options, array $auth): RequestOptions
    {
        switch ((string) ($auth['type'] ?? '')) {
            case 'basic':
                return $options->withAuth((string) ($auth['user'] ?? ''), (string) ($auth['password'] ?? ''));

            case 'bearer':
                return $options->withDefaultHeader('Authorization', 'Bearer ' . (string) ($auth['token'] ?? ''));

            case 'header':
                return $options->withDefaultHeader('Authorization', (string) ($auth['value'] ?? ''));

            default:
                return $options;
        }
    }

    private function proxyTypeConstant(string $type): int
    {
        $map = [
            'http'            => CURLPROXY_HTTP,
            'http1.0'         => defined('CURLPROXY_HTTP_1_0') ? CURLPROXY_HTTP_1_0 : CURLPROXY_HTTP,
            'socks4'          => CURLPROXY_SOCKS4,
            'socks4a'         => defined('CURLPROXY_SOCKS4A') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4,
            'socks5'          => CURLPROXY_SOCKS5,
            'socks5.hostname' => defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5,
        ];

        return $map[$type] ?? CURLPROXY_HTTP;
    }

    private function createCookieStore(string $setting): CookieStoreInterface
    {
        if ($setting === 'none') {
            return new class implements CookieStoreInterface {
                public function cookieHeaderFor(string $url): ?string
                {
                    return null;
                }

                public function collectFrom(Response $response, string $url): void
                {
                }
            };
        }

        return new MemoryCookieStore(); // memory(默认);file:<path> 形态延后
    }

    private function lastFailedId(Report $report): string
    {
        $steps = $report->steps();

        return $steps === [] ? '' : $steps[\count($steps) - 1]->stepId;
    }
}
