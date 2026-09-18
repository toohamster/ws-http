# PHP 7.4 语法特性矩阵(经 `/usr/local/bin/php74 -l` 逐项实测验证,2026-09-10)

> 来源教训:S2 实现时误用 `public readonly int $x`(PHP 8.1+ 语法)导致 parse error。
> **写任何 core 类前先对照本表。** 环境是 PHP 7.4.32,不是 8.x。

## ✅ 可用(实测通过)

| 特性 | 示例 |
| --- | --- |
| **类型化属性(typed properties)**——7.4 核心特性 | `public int $x;` / `private ?int $x = null;` / `public string $y = "a";` / `public array $xs = [];` / `public ?self $next = null;` |
| **箭头函数** | `fn(int $x): int => $x * 2;`(自动捕获外部变量、自动捕获 $this) |
| `??=` 空合并赋值 | `$a ??= 1;` |
| 数组展开(spread) | `[0, ...$a];`(索引数组;带字符串键实测也能过但语义受限,慎用) |
| 数字分隔符 | `1_000_000` |
| `array_key_first()` / `array_key_last()` | — |
| **接口方法(无 body)** + 静态/非静态 | `interface I { public function f(): bool; }` |
| 接口常量(可带可见性) | `interface I { public const A = 1; }` |
| 可空返回 / void 返回 | `function f(): ?int` / `: void` |
| `mixed` 类型提示 | `function f(mixed $x)`(7.4 中仅作提示,不校验) |
| static closure / final class / abstract 方法可见性 | — |
| 返回型变(协变) | 子类方法返回类型可收窄(`object` → `stdClass`) |
| `::class` 魔术常量 / 类常量表达式(`const X = [1,2]`) | — |
| heredoc/nowdoc(含缩进闭合、变量插值) | — |
| `str_contains()` 实测 `-l` 通过 | ⚠ 但运行时是 **PHP 8.0 函数**,7.4 运行会 `Call to undefined function`。**禁用** |
| 属性注解 `#[Deprecated]` | `-l` 通过(被当注释),但语义是 8.0 的;禁用 |

## ❌ 不可用(实测失败,均为 8.x 语法)

| 特性 | 最低版本 | 7.4 替代写法 |
| --- | --- | --- |
| **readonly 属性** | 8.1 | 普通 typed property + 构造后不写;或 private setter |
| **构造器属性提升** | 8.0 | 传统写法:参数赋值给属性 |
| **联合类型** `int\|string` | 8.0 | 无;用 `mixed` 弱化或拆方法 |
| **nullsafe** `?->` | 8.0 | `null !== $a ? $a->b : null` |
| **match 表达式** | 8.0 | `switch` 或数组映射 |
| **命名参数** | 8.0 | 按位置传参 |
| **enum** | 8.1 | 类常量(int/enum 风格)+ `::from` 静态方法自实现 |
| **first-class callable** `strlen(...)` | 8.1 | `'strlen'` 字符串 / `Closure::fromCallable()` |
| **static return type** | 8.0 | `: self` |
| 接口方法**带 body** | ❌ 任何版本都不合法(我误测的假特性) | 接口只声明,默认实现用 abstract class 或 trait |
| `list() ` 带键的混用形态 | ❌ 语法本身错误 | `['k' => $v] = $arr;`(直接解构,7.4 可用) |

## ⚠ 陷阱清单(易混)

1. `readonly` 是 8.1 —— 7.4 想要"只读"效果:typed property + 约定不可变(配合 PHPStan),或 `__get` 只读包装;
2. `str_contains/str_starts_with/str_ends_with` 是 **8.0 运行时函数**,`-l` 查不出来(词法过、运行挂)——一律用 `strpos() !== false` / `substr_compare`;
3. `mixed` 在 7.4 是"伪类型"(不校验),8.0 才强制——可用但别依赖其校验;
4. 构造器里"传统赋值"写法注意 private typed property 必须在赋值前已声明;
5. 检查语法只用 `-l` 不够:运行时函数(如 str_contains)会漏网,需要 `php74 -r 'function_exists(...)'` 或单测兜底;
6. 设计文档中写了 `public readonly` 的签名(design/11 §3 Response、§7 RequestException 等)一律按本表降级实现:**typed property + 文档标注不可变约定**,不改设计文档语义。
7. **`array_is_list()` 是 8.1 运行时函数**(同类陷阱,design/23 实现踩过):判断"纯列表"用 `array_keys($a) === range(0, count($a) - 1)` 或 `$a === array_values($a)`(注意空数组两法都返回 true,恰好符合 is_list 语义)。
