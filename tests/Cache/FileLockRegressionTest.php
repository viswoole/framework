<?php

declare(strict_types=1);

namespace Viswoole\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Swoole\Process;
use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Exception\CacheErrorException;

/** 验证文件缓存租约的清理隔离、期限、持有者校验及跨进程竞争。 */
final class FileLockRegressionTest extends TestCase
{
  private string $storage;
  private File $owner;
  private File $competitor;

  protected function setUp(): void
  {
    $this->storage = sys_get_temp_dir() . '/viswoole-file-lease-' . bin2hex(random_bytes(8));
    $this->owner = new File($this->storage);
    $this->competitor = new File($this->storage);
  }

  protected function tearDown(): void
  {
    $this->owner->clear();
    if (is_dir($this->storage . '/' . File::LOCK_DIRECTORY)) {
      foreach (glob($this->storage . '/' . File::LOCK_DIRECTORY . '/*') as $filename) unlink($filename);
      rmdir($this->storage . '/' . File::LOCK_DIRECTORY);
    }
    if (is_dir($this->storage)) rmdir($this->storage);
  }

  public function testClearPreservesActiveLease(): void
  {
    $id = $this->owner->lock('clear', expire: 0);
    $this->owner->set('value', 'cached');
    self::assertTrue($this->competitor->clear());
    self::assertNull($this->owner->get('value'));
    $this->assertContended('clear');
    self::assertTrue($this->owner->unlock($id));
    $next = $this->competitor->lock('clear', retry: 1);
    self::assertTrue($this->competitor->unlock($next));
  }

  public function testExpiredLeaseCanBeTakenOverAndOldOwnerCannotReleaseIt(): void
  {
    $id = $this->owner->lock('expires', expire: 1);
    $this->assertContended('expires');
    usleep(1100000);
    $next = $this->competitor->lock('expires', retry: 1);
    self::assertNotSame($id, $next);
    self::assertFalse($this->owner->unlock($id));
    $this->assertContended('expires');
    self::assertTrue($this->competitor->unlock($next));
  }

  public function testNonExpiringLeaseRemainsHeld(): void
  {
    $id = $this->owner->lock('forever', expire: 0);
    usleep(1100000);
    $this->assertContended('forever');
    self::assertTrue($this->owner->unlock($id));
    self::assertFalse($this->owner->unlock($id));
  }

  public function testCloseOnlyReleasesAutomaticLeases(): void
  {
    $automatic = $this->owner->lock('automatic', autoUnlock: true);
    $manual = $this->owner->lock('manual');
    $this->owner->close();
    self::assertFalse($this->owner->unlock($automatic));
    $next = $this->competitor->lock('automatic', retry: 1);
    $this->assertContended('manual');
    self::assertTrue($this->owner->unlock($manual));
    self::assertTrue($this->competitor->unlock($next));
  }

  public function testCacheKeysCannotOverwriteLeaseRecords(): void
  {
    $id = $this->owner->lock('../scene', expire: 0);
    mkdir($this->storage . '/lock');
    $this->competitor->set('lock/lock_scene', 'ordinary cache');
    $this->competitor->clear();
    $this->assertContended('../scene');
    self::assertTrue($this->owner->unlock($id));
  }

  public function testClearInAnotherProcessPreservesLease(): void
  {
    $id = $this->owner->lock('process', expire: 0);
    $storage = $this->storage;
    $worker = new Process(static function (Process $process) use ($storage): void {
      $cache = new File($storage);
      $cache->clear();
      try {
        $cache->lock('process', retry: 1);
        $process->write('acquired');
      } catch (CacheErrorException) {
        $process->write('contended');
      }
    }, false, SOCK_STREAM, true);
    self::assertNotFalse($worker->start());
    $worker->setTimeout(3);
    $result = $worker->read();
    $status = Process::wait(true);
    self::assertSame(0, $status['code']);
    self::assertSame('contended', $result);
    self::assertTrue($this->owner->unlock($id));
  }

  public function testConcurrentProcessesCannotBothAcquireLease(): void
  {
    $storage = $this->storage;
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
      $worker = new Process(static function (Process $process) use ($storage): void {
        $process->read();
        $cache = new File($storage);
        try {
          $id = $cache->lock('race', expire: 0, retry: 1);
        } catch (CacheErrorException) {
          $process->write('contended');
          return;
        }
        $process->write('acquired');
        $process->read();
        $cache->unlock($id);
      }, false, SOCK_STREAM, true);
      self::assertNotFalse($worker->start());
      $worker->setTimeout(3);
      $workers[] = $worker;
    }
    foreach ($workers as $worker) $worker->write('start');
    $results = array_map(static fn(Process $worker): string|false => $worker->read(), $workers);
    self::assertCount(1, array_filter($results, static fn(string|false $result): bool => $result === 'acquired'));
    foreach ($workers as $index => $worker) {
      if ($results[$index] === 'acquired') $worker->write('release');
    }
    foreach ($workers as $worker) {
      $status = Process::wait(true);
      self::assertSame(0, $status['code']);
    }
  }

  /**
   * 断言单次竞争失败，避免重试时间掩盖租约状态。
   *
   * @param string $scene 锁场景
   * @return void
   */
  private function assertContended(string $scene): void
  {
    try {
      $this->competitor->lock($scene, retry: 1, sleep: 0);
      self::fail('有效租约不应被其他调用方取得');
    } catch (CacheErrorException $exception) {
      self::assertSame('缓存系统繁忙，请稍后重试', $exception->getMessage());
    }
  }
}
