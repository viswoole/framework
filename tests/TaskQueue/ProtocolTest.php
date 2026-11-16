<?php
/**
 * 职责：验证队列协议边界、普通缓存隔离和单条异常恢复；不初始化业务应用。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 协议外记录明确拒绝，不隐式迁移，也不阻止正常到期任务。
 */
final class ProtocolTest extends TestCase
{
    /**
     * 验证真实 Redis 中的格式边界与故障隔离。
     * @return void
     */
    public function testProtocolBoundaryAndRecoveryIsolation(): void
    {
        $observations = Support\ScenarioRunner::run('protocol-scenarios.php', 10);
        foreach (['unsupportedReadRejected', 'unsupportedUpdateRejected', 'unsupportedNotMigrated',
            'unsupportedObjectNotRestored', 'healthyRecovered', 'brokenRecordLogged', 'ordinaryCacheUnaffected'] as $name) {
            self::assertTrue($observations[$name], $name);
        }
    }
}
