<?php
/**
 * 职责：验证真实 Task Worker 的首次与重试使用同一字节载荷；不依赖业务应用。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 对象钩子必须在同一消费边界触发，不能由恢复者重复执行。
 */
final class ObjectTransportTest extends TestCase
{
    /**
     * 比较真实 IPC 中的恢复次数、生成代次、字节和 ACK。
     * @return void
     */
    public function testOpaquePayloadAcrossRetries(): void
    {
        $observations = Support\ScenarioRunner::run('object-transport.php', 12);
        foreach (['wake', 'serialize'] as $name) {
            foreach (['firstMetric', 'retryMetric', 'sameBytes', 'firstRetried', 'retryResult', 'acked'] as $field) {
                self::assertTrue($observations[$name . ':' . $field], $name . ':' . $field);
            }
        }
        self::assertTrue($observations['serializedOnce']);
    }
}
