<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Cache\CacheManager;
use Viswoole\Cache\Driver\File;
use Viswoole\Core\Config;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 缓存监控测试
 *
 * 验证管理器、显式商店与标签路径，缓存值不能进入记录。
 */
final class CacheMonitoringTest extends TestCase
{
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /**
   * 管理器与标签调用按序记录且不泄漏缓存值；监控关闭时保留原始驱动
   */
  public function testManagerAndTagCallsAreRecordedWithoutCacheValues(): void
  {
    $config = $this->createMock(Config::class);
    $storage = sys_get_temp_dir() . '/monitor_cache_' . bin2hex(random_bytes(6));
    $driver = new File(storage: $storage);
    $config->method('get')->willReturnMap([
      ['cache.stores', [], ['file' => $driver]],
      ['cache.default', 'file', 'file'],
      ['monitor.enabled', false, true],
    ]);
    $cache = new CacheManager($config);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    try {
      self::assertTrue($cache->set('user', 'private-value'));
      self::assertSame('private-value', $cache->store()->get('user'));
      self::assertTrue($cache->store()->tag('users')->set('tagged', 'private-value'));
      $cache->store()->tag('users')->remove('tagged');
      self::assertFalse($cache->has('tagged'));
      $record = $trace->finish();
      self::assertSame(['set', 'get', 'tag.set', 'tag.remove', 'has'], array_column($record['operations'], 'operation'));
      self::assertSame('file', $record['operations'][0]['store']);
      self::assertStringNotContainsString('private-value', json_encode($record));
    } finally {
      TraceContext::clear();
      $driver->clear();
      if (is_dir($storage)) rmdir($storage);
    }
  }

  /**
   * monitor.enabled 为 false 时商店保留原始驱动实例
   */
  public function testDisabledMonitoringPreservesOriginalDriver(): void
  {
    $config = $this->createMock(Config::class);
    $driver = $this->createMock(\Viswoole\Cache\Contract\CacheDriverInterface::class);
    $config->method('get')->willReturnMap([
      ['cache.stores', [], ['file' => $driver]],
      ['cache.default', 'file', 'file'],
      ['monitor.enabled', false, false],
    ]);
    self::assertSame($driver, (new CacheManager($config))->store());
  }
}
