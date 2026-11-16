<?php
/**
 * 职责：使用 PHPUnit 验证隔离 PHP 场景，不在测试进程中初始化 Swoole 服务。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 验证可靠队列消费的真实 Redis 场景。
 */
final class QueueTest extends TestCase
{
    /**
     * @var array<string, mixed> 独立进程返回的观测值
     */
    private static array $observations;

    /**
     * 只运行一次场景，避免重复执行耗时的并发和租约验证。
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$observations = Support\ScenarioRunner::run('scenarios.php', 25);
    }

    /**
     * 验证每项消费契约，并保留独立失败用例名称。
     * @param string $behavior 观测项名称
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBehaviors')]
    public function testBehavior(string $behavior): void
    {
        self::assertTrue(self::$observations[$behavior], $behavior);
    }

    /**
     * 提供场景契约名称，不在收集测试时启动外部服务。
     * @return iterable<string, array{string}> 场景参数
     */
    public static function provideBehaviors(): iterable
    {
        $behaviors = [
            'initialBlocked', 'retainedWhileRunning', 'duplicateBlocked',
            'renewed', 'acked', 'indexRemoved',
            'retryBlocked', 'exhausted', 'failedNotRecovered',
            'replayed', 'replayOnce', 'failedList',
            'failedDelete', 'crashRecovered', 'staleRejected',
            'newOwnerAck', 'deliveryRetained', 'deliveryException',
            'automaticRenewal', 'runningNotRecovered', 'longResult',
            'renewalStopped', 'longAcked', 'ackFailureRetained',
            'serverDeadline', 'ackAfterStoreRecovery', 'actualCrashRecovered',
            'staleCasRejected', 'fileRejected', 'wrappedFileRejected',
            'falseAcked', 'handlerRetried', 'unknownFailed',
            'expiredFailed', 'manualRenew', 'nonQueue',
            'nonQueueExpiry', 'decorator',
        ];
        foreach ($behaviors as $behavior) {
            yield $behavior => [$behavior];
        }
    }

    /**
     * 验证指数退避的抖动范围。
     * @return void
     */
    public function testBackoffRange(): void
    {
        self::assertGreaterThanOrEqual(0.79, self::$observations['backoff']);
        self::assertLessThanOrEqual(1.01, self::$observations['backoff']);
    }

    /**
     * 验证八个独立进程只有一个获得消费权。
     * @return void
     */
    public function testExclusiveConsumption(): void
    {
        self::assertSame(1, self::$observations['competition']);
    }

    /**
     * 验证启动和日志故障不会破坏恢复调度。
     * @return void
     */
    public function testLifecycleRecovery(): void
    {
        $observations = Support\ScenarioRunner::run('lifecycle.php', 25);
        foreach (['startupReturned', 'recoveryScheduled', 'loggedStoreError', 'loggedScanError', 'loggedBackendError'] as $name) {
            self::assertTrue($observations[$name], $name);
        }
    }
}
