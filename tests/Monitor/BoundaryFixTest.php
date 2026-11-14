<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\OperationOutcome;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\ResponseHeaders;
use Viswoole\Monitor\SnapshotBudget;

/** 职责：验证预算、配置和缓存结果分类的修复边界。 */
final class BoundaryFixTest extends TestCase
{
  /** @return void JSON 编码成本必须反映在共享预算中。 */
  public function testEscapedHeadersReserveEncodedBytes(): void
  {
    $options = new MonitorOptions(['max_body_bytes' => 65536, 'max_snapshot_bytes' => 262144]);
    $budget = new SnapshotBudget($options->maxSnapshotBytes);
    $headers = new ResponseHeaders($options, $budget);
    $headers->record('X-Test', str_repeat('\\', 60000));
    // 共享预算应拒绝额外的 140 KiB，而非把 60 KiB 原始值当编码后大小。
    self::assertFalse($budget->replace('other', 140000));
    self::assertFalse($headers->snapshot()['headers_truncated']);
  }

  /** @return void 转义标头和大量操作共同填满时仍不突破总快照预算。 */
  public function testEscapedHeadersAndOperationsStayWithinBudget(): void
  {
    $trace = new RequestTrace(new MonitorOptions([
      'threshold_ms' => 0, 'max_body_bytes' => 65536, 'max_snapshot_bytes' => 262144,
    ]), []);
    $trace->updateResponseHeader('X-Test', str_repeat('\\', 60000));
    for ($index = 0; $index < 200; $index++) {
      $slot = $trace->beginOperation(['type' => 'other', 'detail' => str_repeat('x', 5000)]);
      if ($slot !== null) { $trace->endOperation($slot, hrtime(true), ['success' => true]); }
    }
    $encoded = json_encode($trace->snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    self::assertLessThanOrEqual(262144, strlen($encoded));
  }

  /** @return void 未命中及没有变化的结果不等于基础设施失败。 */
  public function testCacheMissesAndNoOpsDoNotCountAsFailures(): void
  {
    foreach (['getArray', 'getTags', 'sAddArray'] as $operation) {
      self::assertTrue(OperationOutcome::summarize(['type' => 'cache', 'operation' => $operation], false)['success']);
    }
    self::assertFalse(OperationOutcome::summarize(['type' => 'cache', 'operation' => 'set'], false)['success']);
  }

  /** @return void 非法配置不能在强制转换后悄悄生效。 */
  public function testInvalidScalarOptionsAreRejected(): void
  {
    foreach ([
      ['enabled' => 'false'], ['capture_request' => 'false'], ['record_bindings' => 1],
      ['threshold_ms' => 'invalid'], ['threshold_ms' => []], ['threshold_ms' => INF],
      ['max_operations' => 1.9], ['max_body_bytes' => '8192'], ['writer' => 1],
    ] as $options) {
      try {
        new MonitorOptions($options);
        self::fail('非法配置必须在启动期拒绝');
      } catch (InvalidArgumentException $error) {
        self::assertStringContainsString('monitor.', $error->getMessage());
      }
    }
  }
}
