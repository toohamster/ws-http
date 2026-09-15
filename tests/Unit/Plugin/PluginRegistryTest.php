<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Ws\Http\Exception;
use Ws\Http\Plugin\OpenAI\Client as OpenAIClient;
use Ws\Http\Plugin\PluginRegistry;
use Ws\Http\Plugin\WordPress\Client as WpClient;

/**
 * 设计 17 §5:注册机制 + 内建样例(mock HTTP 验证请求契约)。
 */
final class PluginRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        PluginRegistry::resetForTest();
    }

    protected function tearDown(): void
    {
        PluginRegistry::resetForTest();
    }

    // ---------- 注册机制 ----------

    public function testBootDefaultsRegistersBuiltins(): void
    {
        PluginRegistry::bootDefaults();

        self::assertTrue(PluginRegistry::has('openai'));
        self::assertTrue(PluginRegistry::has('wordpress'));
        self::assertSame(['openai', 'wordpress'], PluginRegistry::names());
    }

    public function testBootDefaultsIsIdempotent(): void
    {
        PluginRegistry::bootDefaults();
        PluginRegistry::bootDefaults();

        self::assertSame(['openai', 'wordpress'], PluginRegistry::names());
    }

    public function testDuplicateRegistrationThrows501(): void
    {
        PluginRegistry::bootDefaults();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(501);
        PluginRegistry::register(new class implements \Ws\Http\Contract\PluginInterface {
            public function name(): string
            {
                return 'openai';
            }

            public function register(\Ws\Http\Contract\PluginContext $ctx): void
            {
            }
        });
    }

    public function testUnknownServiceThrows501(): void
    {
        PluginRegistry::bootDefaults();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(501);
        PluginRegistry::service('nope');
    }

    public function testUnknownAuthProviderThrows502(): void
    {
        PluginRegistry::bootDefaults();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(502);
        PluginRegistry::auth('nope');
    }

    // ---------- AuthProvider:内建三种 ----------

    public function testBasicAuthProvider(): void
    {
        PluginRegistry::bootDefaults();
        $options = PluginRegistry::auth('wordpress.application-password')
            ->apply(new \Ws\Http\RequestOptions(), ['user' => 'admin', 'appPassword' => 'xxxx']);

        self::assertSame(['user' => 'admin', 'pass' => 'xxxx', 'method' => CURLAUTH_BASIC], $options->auth());
    }

    public function testBearerAuthProvider(): void
    {
        PluginRegistry::bootDefaults();
        $options = PluginRegistry::auth('openai.bearer')
            ->apply(new \Ws\Http\RequestOptions(), ['apiKey' => 'sk-test']);

        self::assertSame('Bearer sk-test', $options->defaultHeaders()->get('Authorization'));
    }

    public function testBearerAuthProviderRequiresApiKey(): void
    {
        PluginRegistry::bootDefaults();

        $this->expectException(\InvalidArgumentException::class);
        PluginRegistry::auth('openai.bearer')->apply(new \Ws\Http\RequestOptions(), []);
    }

    // ---------- OpenAI 样例:请求契约(mock 验证) ----------

    public function testOpenAIChatCreateRequestContract(): void
    {
        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $factory->queue(new \Ws\Http\Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '{"id":"c1"}', ''));

        $client = new OpenAIClient('sk-test', $this->requestOn($factory));
        $response = $client->chat()->create('你好');

        self::assertSame(200, $response->code);

        $sent = $factory->lastSent();
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $sent['url']);
        // Bearer 头经 options.defaultHeaders → send 内部 formatHeaders 合并;
        // 测试替身直接记录方法参数,所以显式断言 client options 上的头:
        self::assertSame('Bearer sk-test', $client->options()->defaultHeaders()->get('Authorization'));
        // body 结构验证(json_decode 后断言,避免 unicode 编码形态耦合)
        $bodyData = json_decode($sent['body']->content, true);
        self::assertSame('gpt-3.5-turbo', $bodyData['model'] ?? null);
        self::assertSame('你好', $bodyData['messages'][0]['content'] ?? null);
    }

    public function testOpenAIModelsListContract(): void
    {
        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $factory->queue(new \Ws\Http\Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '{"data":[]}', ''));

        $client = new OpenAIClient('sk-test', $this->requestOn($factory));
        $client->models()->list();

        self::assertSame('GET', $factory->lastSent()['method']);
        self::assertSame('https://api.openai.com/v1/models', $factory->lastSent()['url']);
    }

    // ---------- WordPress 样例 ----------

    public function testWpCreatePostContract(): void
    {
        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $factory->queue(new \Ws\Http\Response(['http_code' => 201, 'header_size' => 0, 'total_time' => 0.1], '{"id":7}', ''));

        $client = new WpClient('https://blog.example.com', 'app-pass-123', 'admin', $this->requestOn($factory));
        $response = $client->posts()->create('标题', '正文');

        self::assertSame(201, $response->code);

        $sent = $factory->lastSent();
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://blog.example.com/wp-json/wp/v2/posts', $sent['url']);
        // 标题字段写入 body(json_decode 后断言,避免编码形态耦合)
        $bodyData = json_decode($sent['body']->content, true);
        self::assertSame('标题', $bodyData['title'] ?? null);
    }

    public function testWpListWithQuery(): void
    {
        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $factory->queue(new \Ws\Http\Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '[]', ''));

        $client = new WpClient('https://blog.example.com', 'pw', 'admin', $this->requestOn($factory));
        $client->posts()->list(['per_page' => 5, 'status' => 'draft']);

        self::assertSame('https://blog.example.com/wp-json/wp/v2/posts?per_page=5&status=draft', $factory->lastSent()['url']);
    }

    public function testWpMediaUploadContract(): void
    {
        $factory = new \Ws\Http\Tests\Engine\FakeRequestFactory();
        $factory->queue(new \Ws\Http\Response(['http_code' => 201, 'header_size' => 0, 'total_time' => 0.1], '{"id":9}', ''));

        $tmp = tempnam(sys_get_temp_dir(), 'wshttp');
        file_put_contents($tmp, 'img');

        $client = new WpClient('https://blog.example.com', 'pw', 'admin', $this->requestOn($factory));
        $client->media()->upload($tmp);

        $sent = $factory->lastSent();
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://blog.example.com/wp-json/wp/v2/media', $sent['url']);
        self::assertInstanceOf(\CURLFile::class, $sent['body']['file']);
    }

    public function testWpErrorDetection(): void
    {
        // 替身响应需带 Content-Type 才会触发 JSON 解析(body 才非 false)
        $wpError = new \Ws\Http\Response(['http_code' => 403, 'header_size' => 0, 'total_time' => 0.1], '{"code":"rest_forbidden","message":"no"}', "HTTP/1.1 403 X\r\nContent-Type: application/json\r\n");
        $ok = new \Ws\Http\Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '{"id":1}', "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n");

        self::assertTrue(WpClient::isWpError($wpError));
        self::assertFalse(WpClient::isWpError($ok));
    }

    // ---------- 隔离性:core/functional 无 Plugin 引用 ----------

    public function testCoreAndFunctionalDoNotReferencePluginNamespace(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src/Ws/Http'));

        foreach ($it as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file;
            if (strpos($path, DIRECTORY_SEPARATOR . 'Plugin' . DIRECTORY_SEPARATOR) !== false) {
                continue; // plugin 自身
            }

            $content = file_get_contents($path);
            if (strpos($content, 'Http\\Plugin') !== false) {
                $hits[] = $path;
            }
        }

        self::assertSame([], $hits, 'core/functional 源码不得引用 Plugin 命名空间(design/17 §5): ' . implode(', ', $hits));
    }

    /**
     * 构造绑定到 FakeRequestFactory 的 Request:send 走 SealedRequest 记录。
     */
    private function requestOn(\Ws\Http\Tests\Engine\FakeRequestFactory $factory): \Ws\Http\Request
    {
        return new class($factory) extends \Ws\Http\Request {
            private $factory;

            public function __construct(\Ws\Http\Tests\Engine\FakeRequestFactory $factory)
            {
                parent::__construct();
                $this->factory = $factory;
            }

            public function send(string $method, string $url, $body = null, array $headers = []): \Ws\Http\Response
            {
                $this->factory->record($method, $url, $body, $headers);

                $responses = $this->factory->responses;
                $response = $responses !== [] ? $responses[\count($responses) - 1] : new \Ws\Http\Response(['http_code' => 200, 'header_size' => 0, 'total_time' => 0.1], '{}', '');

                return $response;
            }
        };
    }
}
