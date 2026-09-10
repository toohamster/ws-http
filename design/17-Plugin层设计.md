# 17 Plugin 层设计(plugin:三方系统适配与注册机制)

> 层归属:**plugin**。使用者:业务对接者(用内建 plugin)、插件作者(注册自定义 plugin)。
> 决策:所有内建 plugin 与核心**同包分发**(不拆 composer 包);对外提供**注册机制**供第三方扩展。

**简单路径**(业务对接者视角,只面对语义化方法):

```php
$openai = (new \Ws\Http\Plugin\OpenAI\Client($apiKey));
$reply  = $openai->chat()->create('你好');        // 内部:core Request + Bearer 认证 + 端点封装

$wp = \Ws\Http\Plugin\PluginRegistry::wordpress('https://blog.example.com', 'app-password');
$wp->createPost('标题', '正文');                   // 同一心智模型
```

> 简单路径使用者不需要知道:底层是 core 的 Request、认证如何组装、响应如何解析。这些属于"复杂的事情必须可能"的部分,见 §2/§3。

## 1. Plugin 是什么、不是什么

**是**:对某个三方系统 HTTP API 的**适配层**,产出物固定为三件套——

1. **认证封装**:把三方鉴权(Bearer / Application Password / 令牌签名等)转换为 core 的 `RequestOptions`(withAuth / withDefaultHeader);
2. **端点定义**:系统 API 端点常量与拼装(路径、默认 query、版本号);
3. **语义化方法**:面向业务的调用方法,返回 core `Response`(或解析后的 body)。

**不是**(防过度设计):

- 不是完整 SDK:不做三方响应的深度领域建模/实体映射,返回 Response + 便捷解析即可;
- 不是插件框架:无自动发现、无配置文件加载、无生命周期钩子,注册机制 = 一个注册表类 + 一个接口;
- 不引入新抽象:plugin 全部通过 core 公开 API(Request/RequestOptions/Contract 接口)实现,不得绕过 core 访问 cURL。

## 2. PluginInterface(注册契约,定义在 core `Contract\`)

```php
namespace Ws\Http\Contract;

interface PluginInterface
{
    public function name(): string;          // 唯一标识:'openai' | 'wordpress' | …
    /** 装配点:把 plugin 提供的认证器/端点/注册项挂到运行环境 */
    public function register(PluginContext $ctx): void;
}
```

```php
final class PluginContext        // 注册表暴露给 plugin 的装配面(最小面)
{
    /** 认证器注册:plugin 名 → 认证器工厂 */
    public function addAuth(string $name, callable $factory): void;      // fn(array $config): AuthProviderInterface
    /** 提取 source 注册(经 ExtractorInterface,design/15) */
    public function addExtractor(string $source, ExtractorInterface $impl): void;
    /** 比较操作符注册(经 ComparatorInterface,design/12) */
    public function addComparator(string $op, ComparatorInterface $impl): void;
    /** plugin 自有服务存取(端点定义、语义化客户端实例挂载) */
    public function share(string $key, object $service): void;
}
```

## 3. PluginRegistry(唯一公开入口)

```php
final class PluginRegistry       // 位于 Ws\Http\Plugin\
{
    /** 注册内建 plugin(Bootstrap 时自动完成,用户无需调用) */
    public static function bootDefaults(): void;                         // openai / wordpress

    /** 注册自定义 plugin */
    public static function register(Contract\PluginInterface $plugin): void;   // 重名抛 Exception(501)

    /** 取用 */
    public static function client(string $name, array $config = []): object;   // 语义化客户端实例
    public static function auth(string $name, array $config): AuthProviderInterface;
    public static function has(string $name): bool;
}
```

- **AuthProviderInterface**(core 契约):`apply(RequestOptions $options): RequestOptions`——把认证配置转换为请求配置;core 内建 basic/bearer/header 三种(design/14 §4.2 的 auth 结构),plugin 可注册新方式(如微信签名、OAuth2 流程);
- `auth()` 产生的认证器与场景脚本的 `auth` 字段打通:脚本能用 `{"type": "plugin:wechat-sign", ...}`(细节在实现期定,机制上已预留);
- 注册表是静态简易实现(本库无容器哲学);如需多套隔离配置,实例化 `PluginRegistry` 对象即可,静态门面只是快捷方式。

## 4. 内建 plugin 样例(钉住设计边界)

### 4.1 OpenAI(形态:Bearer 认证 + JSON 端点)

```php
namespace Ws\Http\Plugin\OpenAI;

final class Client
{
    public function __construct(string $apiKey, ?Request $http = null, string $baseUrl = 'https://api.openai.com/v1');

    public function chat(): ChatEndpoint;         // ->create(string|array $prompt): Response
    public function completions(): CompletionsEndpoint;
    public function models(): ModelsEndpoint;     // ->list(): Response
}
```

- 认证:AuthProvider 实现 → `withDefaultHeader('Authorization', 'Bearer '.$apiKey)`;
- 端点:常量表(`POST /chat/completions` 等)+ 统一 JSON body 构造(Body::json);
- 方法返回 `Response`;便捷方法 `->json()` 代理 `$response->body`。**不做**消息对象模型/流式协议(列扩展预留)。

### 4.2 WordPress(形态:Application Password + REST 端点)

```php
final class Client
{
    public function __construct(string $siteUrl, string $appPassword, ?string $user, ?Request $http = null);

    public function posts(): PostsEndpoint;       // ->list/query() ->get($id) ->create($title,$content) ->update($id,…) ->delete($id)
    public function media(): MediaEndpoint;       // ->upload(\CURLFile $file)  复用 core multipart
    public function users(): UsersEndpoint;
}
```

- 认证:Basic(user, appPassword)——core `withAuth` 原生支持,认证器零新代码;
- 端点:REST v2(`/wp-json/wp/v2/posts` 等);
- 错误约定:`wp_error` 字段检测提供 `->isOk()` 级别的便捷判断,不吞 Response。

### 4.3 样例说明的边界

两个内建样例刻意选择不同认证形态(Bearer vs Basic)与响应形态(纯 JSON vs WP 约定),证明三件套模式的覆盖面;**新增内建 plugin = 新增一个自包含目录(认证器 + 端点类 + Client),不触碰 core/functional 代码**——这是 plugin 机制的验收标准。

## 5. 依赖与规则复核

| 规则 | 落实 |
| --- | --- |
| 依赖单向 | plugin → core/functional 公开 API;core 不 import `Ws\Http\Plugin\*` |
| plugin 互不依赖 | OpenAI/WordPress 目录各自封闭;共享只经 core |
| 机制最小化 | PluginInterface + PluginContext + PluginRegistry + AuthProviderInterface,四个类型,无更多 |
| 同包分发 | `Ws\Http\Plugin\OpenAI|WordPress` 在主包内;第三方经 `PluginRegistry::register()` 注入 |

## 6. 测试要点

| 用例组 | 覆盖 |
| --- | --- |
| 注册机制 | 注册/重名冲突(501)/has/client 取用;bootDefaults 幂等 |
| AuthProvider | 内建 basic/bearer/header 应用到 RequestOptions 的结果;自定义认证器注册生效 |
| OpenAI 样例 | mock RequestFactory:chat()->create 产生的请求(method/url/头/body)与真实 API 契约一致 |
| WordPress 样例 | 同上,含 media upload 的 CURLFile multipart |
| 隔离性 | 静态分析断言:core/functional 无对 Plugin 命名空间的引用 |
