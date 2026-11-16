<?php
/**
 * 职责：验证到期索引的性能边界与状态一致性；不依赖业务应用启动。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 所有结果来自真实 Redis 命令和生产状态提交脚本。
 */
final class ScheduleTest extends TestCase
{
    /**
     * 验证空扫描、限量恢复和生命周期索引。
     * @return void
     */
    public function testScheduleContracts(): void
    {
        $observations = Support\ScenarioRunner::run('index-scenarios.php', 15);
        foreach (['idleNoPayloadReads', 'idleNoExistenceReads', 'idleNoDispatch',
            'firstBatchBounded', 'remainingRecovered', 'metadataDoesNotRestoreObjects',
            'renewalMovesSchedule', 'failedUnscheduled', 'replayScheduled', 'ackUnscheduled'] as $name) {
            self::assertTrue($observations[$name], $name);
        }
    }
    /**
     * 恢复投递失败时仅记录可用于定位的摘要。
     * @return void
     */
    public function testRecoveryLogsExcludePayload(): void
    {
        $observations = Support\ScenarioRunner::run('log-redaction.php', 10);
        self::assertTrue($observations['summaryLogged']);
        self::assertTrue($observations['payloadExcluded']);
    }
}
