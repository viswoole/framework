<?php

declare(strict_types=1);

/* 职责：验证简洁请求 ID 入口的生命周期及协程隔离。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/** 请求 ID 只读入口行为契约。 */
final class RequestIdAccessorTest extends TestCase
{
  /**
   * 清理合成请求上下文
   *
   * @return void
   */
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /**
   * 请求期间可读，结束后消失
   *
   * @return void
   */
  public function testRequestIdFollowsRequestLifecycle(): void
  {
    TraceContext::clear();
    self::assertNull(TraceContext::requestId());
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 0,
      'request_id' => ['generator' => static fn (): string => 'accessor-id']]), $writer);
    $monitor->start('/test', static fn (): array => []);
    self::assertSame('accessor-id', TraceContext::requestId());
    $monitor->finish();
    self::assertSame('accessor-id', $writer->snapshots[0]->requestId);
    self::assertNull(TraceContext::requestId());
  }

  /**
   * 关闭或排除路径无 ID
   *
   * @return void
   */
  public function testUncollectedRequestsHaveNoId(): void
  {
    foreach ([['enabled' => false], ['enabled' => true, 'exclude_paths' => ['/skip']]] as $config) {
      $monitor = new SlowRequestMonitor(new MonitorOptions($config), new InMemoryTraceWriter());
      $monitor->start('/skip', static fn (): array => []);
      self::assertNull(TraceContext::requestId());
    }
  }

  /**
   * 并发请求不会读取其他协程的 ID
   *
   * @return void
   */
  public function testConcurrentRequestIdsAreIsolated(): void
  {
    Coroutine\run(static function (): void {
      $done = new Channel(2);
      foreach (['first-id', 'second-id'] as $id) {
        Coroutine::create(static function () use ($id, $done): void {
          $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true,
            'request_id' => ['generator' => static fn (): string => $id]]), new InMemoryTraceWriter());
          $monitor->start('/test', static fn (): array => []);
          Coroutine::sleep(0.001);
          self::assertSame($id, TraceContext::requestId());
          $monitor->finish();
          self::assertNull(TraceContext::requestId());
          $done->push(true);
        });
      }
      $done->pop();
      $done->pop();
      self::assertNull(TraceContext::requestId());
    });
  }
}
