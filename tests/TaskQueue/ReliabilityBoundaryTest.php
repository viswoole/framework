<?php
/**
 * 职责：用真实 Redis 验证批次饥饿、快照隔离竞争和缓存键共存。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 每个结果对应一个可独立定位的存储或并发不变量。
 */
final class ReliabilityBoundaryTest extends TestCase
{
    /**
     * @var array<string, mixed> 独立场景结果
     */
    private static array $observations;

    /**
     * 场景只运行一次，避免重复 fork 与真实存储操作。
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$observations = Support\ScenarioRunner::run('reliability-boundaries.php', 15);
    }

    /**
     * 验证指定不变量。
     * @param string $behavior 观测项
     * @return void
     */
    #[DataProvider('provideBehaviors')]
    public function testBehavior(string $behavior): void
    {
        self::assertTrue(self::$observations[$behavior], $behavior);
    }

    /**
     * 提供并发与恢复边界用例。
     * @return iterable<string, array{string}> 场景参数
     */
    public static function provideBehaviors(): iterable
    {
        foreach (['emptyKeyAllowsPersist', 'emptyKeyAllowsAck', 'emptyKeyUnchanged',
            'firstBatchQuarantined', 'healthyAfterPoisonBatch', 'rawBytesPreserved',
            'poisonGroupPruned', 'directoryPruned', 'repairedRecordNotQuarantined',
            'repairedRecordRecovered', 'failedQuarantineRetainsRaw', 'failedQuarantineAllowsHealthy',
            'quarantineRetrySucceeds', 'jsonQuarantinePreservesBinary',
            'oneQuarantineWinner', 'concurrentSnapshotPreserved', 'invalidMetadataQuarantined',
            'missingRecordIndexesPruned', 'missingRecordNotQuarantined'] as $name) {
            yield $name => [$name];
        }
    }
}
