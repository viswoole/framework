<?php
/**
 * 职责：使用 PHPUnit 验证隔离 PHP 场景，不在测试进程中初始化 Swoole 服务。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 验证真实服务的停止窗口、回执和进程清理，不依赖 Redis。
 */
final class ShutdownTest extends TestCase
{
    /**
     * @var array<string, mixed> 真实服务退出观测
     */
    private static array $observations;

    /**
     * 将服务场景与 PHPUnit 进程隔离，避免全局桩和 Swoole 状态相互污染。
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$observations = Support\ScenarioRunner::run('shutdown-scenarios.php', 45);
        self::$observations['frameworkConfig'] = Support\ScenarioRunner::run('shutdown-config.php', 5);
    }

    /**
     * 真实 Server 把服务级窗口传入 Swoole，并与停止命令使用相同 PID 路径。
     * @return void
     */
    public function testServerConfiguration(): void
    {
        self::assertEquals([
            'mergedWait' => 12, 'nativeWait' => 12, 'reloadAsync' => true,
            'pidMatches' => true, 'hasWorkerExitHook' => true,
        ], self::$observations['frameworkConfig']);
    }

    /**
     * 任务和请求计数在异常退出时归零并保留原始返回值。
     * @return void
     */
    public function testCallbackCounters(): void
    {
        self::assertEquals([
            'taskDuring' => 1, 'taskAfter' => 0, 'taskResult' => ['ok' => true],
            'requestDuring' => 1, 'requestAfter' => 0, 'requestThrew' => true,
        ], self::$observations['counters']);
    }

    /**
     * 开启协程优雅退出并统一全局、服务覆盖与命令等待预算。
     * @return void
     */
    public function testShutdownPolicy(): void
    {
        self::assertEquals(['reload_async' => true, 'max_wait_time' => 60], self::$observations['policy']['defaults']);
        self::assertEquals(['reload_async' => true, 'max_wait_time' => 12], self::$observations['policy']['options']);
        self::assertSame(29, self::$observations['policy']['waitSeconds']);
        self::assertSame(4, self::$observations['policy']['rejected']);
    }

    /**
     * 运行超过旧 10 秒上限的任务完成后才输出正常停止，并采用实际启动配置。
     * @return void
     */
    public function testGracefulLongTask(): void
    {
        $messages = self::$observations['graceful']['messages'];
        $lastMessage = $messages[array_key_last($messages)];
        self::assertTrue(self::$observations['graceful']['completed']);
        self::assertSame('graceful', self::$observations['graceful']['status']);
        self::assertSame(0, self::$observations['graceful']['activeTasks']);
        self::assertGreaterThan(10, self::$observations['graceful']['elapsed']);
        self::assertSame('SUCCESS', $lastMessage['level']);
        self::assertStringContainsString('正常停止', $lastMessage['message']);
        self::assertFalse(self::$observations['graceful']['hasAliveProcesses']);
        self::assertFalse(self::$observations['graceful']['hasPidFile']);
    }

    /**
     * 强制重启也等待超过旧 5 秒上限的任务，正常结束后才启动新服务。
     * @return void
     */
    public function testForcedRestart(): void
    {
        $messages = self::$observations['restart']['messages'];
        $lastMessage = $messages[array_key_last($messages)];
        self::assertTrue(self::$observations['restart']['completed']);
        self::assertSame('graceful', self::$observations['restart']['status']);
        self::assertGreaterThan(5, self::$observations['restart']['elapsed']);
        self::assertTrue(self::$observations['restart']['restarted']);
        self::assertFalse(self::$observations['restart']['hasAliveProcesses']);
        self::assertSame('SUCCESS', $lastMessage['level']);
    }

    /**
     * Swoole 内部超时中断任务时报告强停，不能伪报任务全部结束。
     * @return void
     */
    public function testSwooleTimeout(): void
    {
        $messages = self::$observations['swooleTimeout']['messages'];
        $lastMessage = $messages[array_key_last($messages)];
        self::assertFalse(self::$observations['swooleTimeout']['completed']);
        self::assertSame('forced', self::$observations['swooleTimeout']['status']);
        self::assertGreaterThan(0, self::$observations['swooleTimeout']['activeTasks']);
        self::assertSame('WARNING', $lastMessage['level']);
        self::assertStringNotContainsString('正常停止', self::$observations['swooleTimeout']['nativeMessages']);
        self::assertFalse(self::$observations['swooleTimeout']['hasAliveProcesses']);
    }

    /**
     * 框架预算耗尽后强停整个进程树，输出警告且仅在全部退出后清理 PID。
     * @return void
     */
    public function testFrameworkTimeout(): void
    {
        $messages = self::$observations['frameworkTimeout']['messages'];
        $lastMessage = $messages[array_key_last($messages)];
        self::assertGreaterThanOrEqual(7, self::$observations['frameworkTimeout']['elapsed']);
        self::assertSame('WARNING', $lastMessage['level']);
        self::assertStringContainsString('强停', $lastMessage['message']);
        self::assertFalse(self::$observations['frameworkTimeout']['hasAliveProcesses']);
        self::assertFalse(self::$observations['frameworkTimeout']['hasPidFile']);
    }

    /**
     * 旧实例缺少回执时明确表达无法确认任务状态。
     * @return void
     */
    public function testLegacyReceipt(): void
    {
        $messages = self::$observations['legacy']['messages'];
        $lastMessage = $messages[array_key_last($messages)];
        self::assertStringContainsString('缺少停机回执', self::$observations['unconfirmedMessage']);
        self::assertStringNotContainsString('正常停止', self::$observations['unconfirmedMessage']);
        self::assertFalse(self::$observations['legacy']['hasAliveProcesses']);
        self::assertFalse(self::$observations['legacy']['hasPidFile']);
        self::assertSame('WARNING', $lastMessage['level']);
        self::assertStringContainsString('缺少停机回执', $lastMessage['message']);
    }
}
