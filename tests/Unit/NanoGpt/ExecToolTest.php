<?php

declare(strict_types=1);

namespace Ws\Http\Tests\Unit\NanoGpt;

use PHPUnit\Framework\TestCase;
use Ws\Http\NanoGpt\Sandbox;
use Ws\Http\NanoGpt\Tool\ExecTool;

/**
 * design/21 §8:ExecTool(白名单/cwd 锁定/超时/截断)。
 */
final class ExecToolTest extends TestCase
{
    private string $runtime;

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir() . '/ws-exec-' . uniqid();
        mkdir($this->runtime, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->runtime));
    }

    public function testDisabledWhenNoBinaries(): void
    {
        $tool = new ExecTool([], $this->runtime);

        self::assertStringContainsString('disabled', $tool->execute(['cmd' => 'php -r "echo 1;"']));
    }    public function testBinaryWhitelistRejection(): void
    {
        $tool = new ExecTool(['jq'], $this->runtime);

        // sh 在默认兜底黑名单:拒绝消息为 denied;curl 不在白名单:消息为 not in the allowed list
        self::assertStringContainsString('is denied', $tool->execute(['cmd' => 'sh -c "echo hi"']));
        self::assertStringContainsString('not in the allowed list', $tool->execute(['cmd' => 'curl http://evil.example.com']));
    }

    // ---------- 防线 2:兜底黑名单 ----------

    public function testDenyBinariesEvenIfWhitelisted(): void
    {
        // 使用者误把 rm 加进白名单:黑名单仍拦
        $tool = new ExecTool(['rm', 'grep'], $this->runtime);

        self::assertStringContainsString('is denied', $tool->execute(['cmd' => 'rm -rf /']));
        self::assertStringContainsString('exit: 0', (string) $tool->execute(['cmd' => 'grep --version']));
    }

    // ---------- 防线 3:操作符扫描 ----------

    public function testShellOperatorsRejected(): void
    {
        $tool = new ExecTool(['cat', 'grep'], $this->runtime);

        self::assertStringContainsString('not allowed', $tool->execute(['cmd' => 'cat a.txt; rm -rf /']));
        self::assertStringContainsString('not allowed', $tool->execute(['cmd' => 'cat a.txt | xargs echo']));
        self::assertStringContainsString('not allowed', $tool->execute(['cmd' => 'cat a.txt && echo done']));
        self::assertStringContainsString('not allowed', $tool->execute(['cmd' => 'echo `whoami`']));
        self::assertStringContainsString('not allowed', $tool->execute(['cmd' => 'grep x f > /etc/hosts']));
    }

    // ---------- 防线 4:任意代码入口 ----------

    public function testArbitraryCodeEntryRejected(): void
    {
        $tool = new ExecTool(['php', 'node', 'sh'], $this->runtime);

        // php -r 内的引号串不含操作符 → 抵达防线 4
        self::assertStringContainsString('denied', $tool->execute(['cmd' => 'php -r unlink_x']));
        self::assertStringContainsString('denied', $tool->execute(['cmd' => 'php -B echo_1']));
        self::assertStringContainsString('denied', $tool->execute(['cmd' => 'node -e require_fs']));
        // 正常脚本执行不受影响:先 write_file 到草稿区,再跑脚本文件
        file_put_contents($this->runtime . '/ok.php', '<?php echo "fine";');
        self::assertStringContainsString('fine', (string) $tool->execute(['cmd' => 'php ok.php']));
    }

    // ---------- 正常执行 ----------

    public function testSuccessfulExecutionWithCwdLock(): void
    {
        file_put_contents($this->runtime . '/hello.php', '<?php echo getcwd(), "|", PHP_EOL; echo file_exists("note.txt") ? "yes" : "no";');
        file_put_contents($this->runtime . '/note.txt', 'x');
        $tool = new ExecTool(['php'], $this->runtime);

        $result = $tool->execute(['cmd' => 'php hello.php']);

        self::assertStringContainsString('exit: 0', $result);
        // cwd 锁定在 runtime 目录
        self::assertStringContainsString($this->runtime, $result);
        self::assertStringContainsString('yes', $result, '脚本可见草稿区内文件');
    }

    public function testOutputTruncation(): void
    {
        file_put_contents($this->runtime . '/big.php', '<?php echo str_repeat("A", 10000);');
        $tool = new ExecTool(['php'], $this->runtime, 30, 50);

        $result = $tool->execute(['cmd' => 'php big.php']);

        self::assertLessThan(200, strlen($result));
    }

    public function testTimeoutTerminates(): void
    {
        file_put_contents($this->runtime . '/slow.php', '<?php sleep(10);');
        $tool = new ExecTool(['php'], $this->runtime, 1);

        $result = $tool->execute(['cmd' => 'php slow.php']);

        self::assertStringContainsString('timed out after 1s', $result);
    }
}
