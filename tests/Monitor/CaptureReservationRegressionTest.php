<?php

declare(strict_types=1);

/* 职责：复现固定来源预留挤占业务明细，并验证在途规则与总预算安全。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\Runtime\MemoryStateStore;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\SnapshotBudget;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\InterfaceCatalog;
use Viswoole\Router\RouteIdentity;

/** 回归测试关注可保留的真实操作明细，不为属性布局编写脆弱测试。 */
final class CaptureReservationRegressionTest extends TestCase
{
  /** @return void 无覆盖规则时应回收空余来源预留，220KiB 操作不提前截断 */
  public function testNoOverrideKeepsOperationsBeyondOldQuarterReservation(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), ['path' => '/orders/1']);
    $trace->freezeCapturePolicy(new RouteIdentity('GET', '/orders/{id}', ['*']));
    TraceContext::attach($trace);
    try {
      for ($index = 0; $index < 55; $index++) {
        TraceContext::measure(['type' => 'other', 'payload' => str_repeat('x', 4000)], static fn (): bool => true);
      }
      $record = $trace->finish();
      self::assertCount(55, $record['operations']);
      self::assertSame(0, $record['dropped_operations']);
      self::assertFalse($record['snapshot_truncated']);
      self::assertLessThanOrEqual(262144, SnapshotBudget::size($record));
    } finally { TraceContext::clear(); }
  }

  /** @return void 路由前新增规则仍可被采纳，固定后按候选上界缩减且不漏来源 */
  public function testRulePublishedDuringRoutingStillHasEnoughReservation(): void
  {
    $catalog = new InterfaceCatalog();
    $route = new RouteIdentity('GET', '/orders/{id}', ['*']);
    $catalog->register('orders', ['template' => $route->template, 'methods' => ['GET'], 'domains' => ['*']]);
    $catalog->complete();
    $options = new MonitorOptions(['threshold_ms' => 0, 'max_snapshot_bytes' => 16384]);
    $runtime = new MonitorRuntime($options, $catalog, new MemoryStateStore(RuntimeState::initial(0)));
    $trace = new RequestTrace($options, []);
    $trace->attachRuntime($runtime);
    $runtime->setOverride('during-routing', $route, 0);
    $trace->freezeCapturePolicy($route);
    $budget = (new ReflectionProperty($trace, 'budget'))->getValue($trace);
    $slots = (new ReflectionProperty($budget, 'slots'))->getValue($budget);
    self::assertLessThan(4096, $slots['capture']);
    $runtime->removeOverride('during-routing');
    $snapshot = $trace->snapshot();
    self::assertSame('during-routing', $snapshot->capture->matchedOverrides[0]->id);
    self::assertLessThanOrEqual($slots['capture'], SnapshotBudget::size($snapshot->toArray()['capture']));
    self::assertLessThanOrEqual(16384, SnapshotBudget::size($snapshot->toArray()));
  }
}
