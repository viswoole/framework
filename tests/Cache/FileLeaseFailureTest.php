<?php

declare(strict_types=1);

namespace Viswoole\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Swoole\Process;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;
use function Swoole\Coroutine\run;
use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Driver\File\LockStore;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Tests\Cache\Support\LeaseWriteFault;

require_once __DIR__ . '/Support/LeaseWriteFault.php';

/** 检验自动释放与原子提交在竞争及文件故障下的行为。 */
final class FileLeaseFailureTest extends TestCase
{
  private string $storage;
  private string $directory;
  private LockStore $store;

  protected function setUp(): void
  {
    $this->storage = sys_get_temp_dir() . '/viswoole-lease-failure-' . bin2hex(random_bytes(8));
    $this->directory = $this->storage . '/' . File::LOCK_DIRECTORY;
    $this->store = new LockStore($this->directory);
  }

  protected function tearDown(): void
  {
    LeaseWriteFault::$directory = null;
    LeaseWriteFault::$isFlushFailure = false;
    foreach (glob($this->directory . '/*') as $filename) unlink($filename);
    if (is_dir($this->directory)) rmdir($this->directory);
    if (is_dir($this->storage)) rmdir($this->storage);
  }

  public function testDestructorWaitsForCompetingMutexBeforeReleasingLease(): void
  {
    $owner = new File($this->storage);
    $owner->lock('destructor', expire: 0, autoUnlock: true);
    $filename = $this->directory . '/' . hash('sha256', 'lock_destructor');
    $worker = new Process(static function (Process $process) use ($filename): void {
      $handle = fopen($filename, 'c+');
      flock($handle, LOCK_EX);
      $process->write('held');
      usleep(150000);
      flock($handle, LOCK_UN);
      fclose($handle);
    }, false, SOCK_STREAM, true);
    self::assertNotFalse($worker->start());
    $worker->setTimeout(3);
    self::assertSame('held', $worker->read());
    unset($owner);
    $status = Process::wait(true);
    self::assertSame(0, $status['code']);
    $competitor = new File($this->storage);
    $id = $competitor->lock('destructor', retry: 1);
    self::assertTrue($competitor->unlock($id));
  }

  public function testHookedDestructorYieldsToMutexHolderInSameWorker(): void
  {
    $storage = $this->storage;
    $flags = Runtime::getHookFlags();
    Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    try {
      run(static function () use ($storage): void {
        $owner = new File($storage);
        $owner->lock('coroutine', expire: 0, autoUnlock: true);
        $filename = $storage . '/' . File::LOCK_DIRECTORY . '/' . hash('sha256', 'lock_coroutine');
        $ready = new Channel(1);
        Coroutine::create(static function () use ($filename, $ready): void {
          $handle = fopen($filename, 'c+');
          flock($handle, LOCK_EX);
          $ready->push(true);
          Coroutine::sleep(0.05);
          flock($handle, LOCK_UN);
          fclose($handle);
        });
        self::assertTrue($ready->pop(3));
        unset($owner);
        $competitor = new File($storage);
        $id = $competitor->lock('coroutine', retry: 1);
        self::assertTrue($competitor->unlock($id));
      });
    } finally {
      Runtime::enableCoroutine($flags);
    }
  }

  public function testAbandonedPendingFileDoesNotBlockLeaseRecovery(): void
  {
    self::assertTrue($this->store->acquire('failure', 'old', 1));
    file_put_contents($this->recordFilename() . '.pending-abandoned', '{"id":');
    self::assertFalse($this->store->acquire('failure', 'early', 1));
    usleep(1100000);
    self::assertTrue($this->store->acquire('failure', 'next', 1));
    self::assertTrue($this->store->release('failure', 'next'));
  }

  public function testShortWriteDoesNotPublishAnIncompleteFirstLease(): void
  {
    LeaseWriteFault::$directory = $this->directory;
    $this->assertCommitFails('写入文件租约失败');
    LeaseWriteFault::$directory = null;
    self::assertFileDoesNotExist($this->recordFilename());
    self::assertSame([], glob($this->directory . '/*.pending-*'));
    self::assertTrue($this->store->acquire('failure', 'next', 1));
    self::assertTrue($this->store->release('failure', 'next'));
  }

  public function testShortWritePreservesPreviousExpiredLease(): void
  {
    $this->assertFailedReplacementPreservesRecord(false);
  }

  public function testFlushFailurePreservesPreviousExpiredLease(): void
  {
    $this->assertFailedReplacementPreservesRecord(true);
  }

  /**
   * 对过期接管注入故障，检查旧记录不变且下一次接管可成功。
   *
   * @param bool $isFlushFailure 是否模拟刷新失败
   * @return void
   */
  private function assertFailedReplacementPreservesRecord(bool $isFlushFailure): void
  {
    self::assertTrue($this->store->acquire('failure', 'old', 1));
    $before = file_get_contents($this->recordFilename());
    usleep(1100000);
    LeaseWriteFault::$directory = $this->directory;
    LeaseWriteFault::$isFlushFailure = $isFlushFailure;
    $this->assertCommitFails($isFlushFailure ? '刷新文件租约失败' : '写入文件租约失败');
    LeaseWriteFault::$directory = null;
    self::assertSame($before, file_get_contents($this->recordFilename()));
    self::assertSame([], glob($this->directory . '/*.pending-*'));
    self::assertTrue($this->store->acquire('failure', 'next', 1));
    self::assertFalse($this->store->release('failure', 'old'));
    self::assertTrue($this->store->release('failure', 'next'));
  }

  /**
   * 断言失败是可观察的，避免把文件错误误报为普通竞争失败。
   *
   * @param string $message 预期错误消息
   * @return void
   */
  private function assertCommitFails(string $message): void
  {
    try {
      $this->store->acquire('failure', 'failed', 1);
      self::fail('预期租约提交失败');
    } catch (CacheErrorException $exception) {
      self::assertStringContainsString($message, $exception->getMessage());
    }
  }

  /** @return string 当前故障场景的数据文件路径 */
  private function recordFilename(): string
  {
    return $this->directory . '/' . hash('sha256', 'failure') . '.json';
  }
}
