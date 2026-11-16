<?php
/**
 * 职责：验证场景超时后终止整个独立进程组；不连接 Redis 或业务服务。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Core\Server\Shutdown\ServerProcessTree;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 捕获真实子进程标识，验证超时清理不遗留运行中的后代。
 */
final class ScenarioRunnerTest extends TestCase
{
    /**
     * 预算耗尽必须报告超时，并终止已经创建的子进程。
     * @return void
     */
    public function testTimeoutTerminatesDescendants(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'queue-child-pid-');
        if ($path === false) throw new RuntimeException('创建子进程记录失败');
        $previous = getenv('QUEUE_TEST_CHILD_PID_FILE');
        putenv('QUEUE_TEST_CHILD_PID_FILE=' . $path);
        try {
            try {
                Support\ScenarioRunner::run('hanging-process-tree.php', 1);
                self::fail('场景应超时');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('超过 1 秒执行预算', $exception->getMessage());
            }
            $pid = (int)file_get_contents($path);
            self::assertGreaterThan(1, $pid);
            self::assertFalse((new ServerProcessTree($pid))->isAlive());
        } finally {
            putenv($previous === false ? 'QUEUE_TEST_CHILD_PID_FILE' : 'QUEUE_TEST_CHILD_PID_FILE=' . $previous);
            unlink($path);
        }
    }
}
