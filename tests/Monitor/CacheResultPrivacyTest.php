<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\MonitoredDriver;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/** 验证缓存结果摘要按操作语义隐藏内容，同时保留业务返回值。 */
final class CacheResultPrivacyTest extends TestCase
{
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /** @return iterable<string,array{string,mixed}> 缓存内容读取与计数更新场景 */
  public static function provideCacheValues(): iterable
  {
    foreach (['get', 'pull'] as $method) {
      foreach ([424242, 123.45, true, false, 'private', ['code' => 424242], null] as $value) {
        yield $method . '-' . get_debug_type($value) . '-' . json_encode($value) => [$method, $value];
      }
    }
    yield 'increment-content' => ['inc', 424242];
    yield 'decrement-content' => ['dec', 424242];
  }

  #[DataProvider('provideCacheValues')]
  public function testCacheContentsAreOmitted(string $method, mixed $value): void
  {
    $driver = $this->createMock(CacheDriverInterface::class);
    $driver->method($method)->willReturn($value);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    self::assertSame($value, (new MonitoredDriver($driver, 'test'))->$method('code'));
    $operation = $trace->finish()['operations'][0];
    self::assertNull($operation['result']);
    self::assertSame(get_debug_type($value), $operation['result_type']);
    self::assertSame(is_array($value) ? count($value) : null, $operation['result_count']);
  }

  /**
   * 写类缓存操作返回 false 计入失败统计，读类操作 false 属未命中不算失败
   */
  public function testFailedCacheWriteCountsAsFailureButMissDoesNot(): void
  {
    $driver = $this->createMock(CacheDriverInterface::class);
    $driver->method('set')->willReturn(false);
    $driver->method('has')->willReturn(false);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    $cache = new MonitoredDriver($driver, 'test');
    $cache->set('code', 'value');
    $cache->has('code');
    $summary = $trace->finish()['operation_summary']['cache'];
    self::assertSame(2, $summary['count']);
    self::assertSame(1, $summary['failed_count']);
  }

  public function testMutationStatusAndDeletionCountRemainAvailable(): void
  {
    $driver = $this->createMock(CacheDriverInterface::class);
    $driver->method('set')->willReturn(false);
    $driver->method('delete')->willReturn(2);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    $cache = new MonitoredDriver($driver, 'test');
    self::assertFalse($cache->set('code', 'value'));
    self::assertSame(2, $cache->delete(['first', 'second']));
    $operations = $trace->finish()['operations'];
    self::assertFalse($operations[0]['result']);
    self::assertSame(2, $operations[1]['result']);
  }
}
