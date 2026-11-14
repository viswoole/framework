<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/**
 * 慢请求监控器测试
 *
 * 验证请求收尾、旁路、异常语义及真正的协程并发隔离。
 */
final class SlowRequestMonitorTest extends TestCase
{
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /**
   * 未启用或命中排除路径时不读取请求快照、不挂载上下文、不产生记录
   */
  public function testDisabledAndExcludedPathsDoNotReadSnapshot(): void
  {
    $writer = new InMemoryTraceWriter();
    $snapshot = static function (): array { throw new RuntimeException('不应该采集'); };
    (new SlowRequestMonitor(new MonitorOptions(), $writer))->start('/', $snapshot);
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/health*']]);
    (new SlowRequestMonitor($options, $writer))->start('/health/live', $snapshot);
    self::assertNull(TraceContext::current());
    self::assertSame([], $writer->records);
  }

  /**
   * 仅慢请求写入记录且上下文被清理；重复 finish 幂等；快请求不产生记录
   */
  public function testOnlySlowRequestsAreWrittenAndContextIsCleared(): void
  {
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 0]), $writer);
    $monitor->start('/', static fn(): array => ['path' => '/']);
    self::assertSame(42, TraceContext::measure(['type' => 'cache', 'operation' => 'delete'], static fn(): int => 42));
    $monitor->finish();
    $monitor->finish();
    self::assertCount(1, $writer->records);
    self::assertSame(42, $writer->records[0]['operations'][0]['result']);
    self::assertNull(TraceContext::current());
    $fastMonitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 1e9]), $writer);
    $fastMonitor->start('/', static fn(): array => []);
    $fastMonitor->finish();
    self::assertCount(1, $writer->records);
  }

  /**
   * 操作失败保留原异常语义，记录中只含异常类型不含异常消息
   */
  public function testFailedOperationsKeepOriginalException(): void
  {
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 0]), $writer);
    $monitor->start('/', static fn(): array => []);
    $exception = new RuntimeException('private credential');
    try {
      TraceContext::measure(['type' => 'database'], static function () use ($exception): never { throw $exception; });
      self::fail('应抛出原异常');
    } catch (RuntimeException $caught) {
      self::assertSame($exception, $caught);
    }
    $monitor->finish();
    $operation = $writer->records[0]['operations'][0];
    self::assertFalse($operation['success']);
    self::assertSame(RuntimeException::class, $operation['exception']);
    self::assertStringNotContainsString('private credential', json_encode($writer->records));
  }

  /**
   * 协程并发请求的上下文与记录相互隔离
   */
  public function testConcurrentRequestsRemainIsolated(): void
  {
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 0]), $writer);
    \Swoole\Coroutine\run(static function () use ($monitor): void {
      $done = new Channel(2);
      foreach (['/first', '/second'] as $path) {
        Coroutine::create(static function () use ($monitor, $path, $done): void {
          $monitor->start($path, static fn(): array => ['path' => $path]);
          Coroutine::sleep(0.001);
          TraceContext::measure(['path' => $path], static fn(): bool => true);
          $monitor->finish();
          $done->push(TraceContext::current() === null);
        });
      }
      $done->pop();
      $done->pop();
    });
    self::assertCount(2, $writer->records);
    self::assertNotSame($writer->records[0]['request_id'], $writer->records[1]['request_id']);
    foreach ($writer->records as $record) {
      self::assertSame($record['request']['path'], $record['operations'][0]['path']);
    }
  }
}
