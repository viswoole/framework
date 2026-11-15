<?php

declare(strict_types=1);

/* 职责：通过真实互斥量和进程争锁复现事件循环阻塞，不猜测 JSON 耗时。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Process;
use Viswoole\Monitor\Runtime\ProcessMutex;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\Runtime\SharedStateStore;

/** 使用有限子进程持锁时间，复现失败也不会挂住测试进程。 */
final class SharedLockRegressionTest extends TestCase
{
  /** @return void 配置转换不得持有跨进程互斥量 */
  public function testStateTransformationRunsOutsideSharedLock(): void
  {
    $store = new SharedStateStore(1000, 4);
    $lock = $this->lock($store);
    $canAcquire = false;
    $store->update(static function (RuntimeState $state) use ($lock, &$canAcquire): RuntimeState {
      $canAcquire = $lock->tryAcquire();
      if ($canAcquire) { $lock->release(); }
      return new RuntimeState(['threshold_ms' => 42.0, 'runtime_id' => $state->runtimeId, 'version' => $state->version + 1]);
    });
    self::assertTrue($canAcquire, '转换和索引构建不应在共享锁内执行');
    self::assertSame(42.0, $store->read()->thresholdMs);
  }

  /** @return void 别的进程持锁时，当前 worker 中的心跳协程仍应先于读取结束执行 */
  public function testContendedReadDoesNotFreezeCoroutineScheduler(): void
  {
    $store = new SharedStateStore(1000, 4);
    $lock = $this->lock($store);
    $holder = new Process(static function (Process $process) use ($lock): void {
      $lock->acquire();
      $process->write('locked');
      usleep(30_000);
      $lock->release();
      $process->exit(0);
    }, false, SOCK_STREAM, false);
    self::assertNotFalse($holder->start());
    self::assertSame('locked', $holder->read());
    $isReadComplete = false;
    $heartbeatObservedCompletion = null;
    Coroutine\run(static function () use ($store, &$isReadComplete, &$heartbeatObservedCompletion): void {
      $done = new Channel(1);
      Coroutine::create(static function () use (&$isReadComplete, &$heartbeatObservedCompletion, $done): void {
        Coroutine::sleep(0.001);
        $heartbeatObservedCompletion = $isReadComplete;
        $done->push(true);
      });
      $store->read();
      $isReadComplete = true;
      $done->pop();
    });
    $status = Process::wait(true);
    self::assertSame(0, $status['code']);
    self::assertFalse($heartbeatObservedCompletion, '争锁期间心跳必须继续调度');
  }

  /** @return void 持锁进程被强杀后，内核释放文件锁，其他进程仍能读写 */
  public function testKilledHolderDoesNotLeavePermanentLock(): void
  {
    $store = new SharedStateStore(1000, 4);
    $lock = $this->lock($store);
    $holder = new Process(static function (Process $process) use ($lock): void {
      $lock->acquire();
      $process->write('locked');
      Process::kill((int)getmypid(), SIGKILL);
    }, false, SOCK_STREAM, false);
    self::assertNotFalse($holder->start());
    self::assertSame('locked', $holder->read());
    $status = Process::wait(true);
    self::assertSame(SIGKILL, $status['signal']);
    self::assertSame(1000.0, $store->read()->thresholdMs);
    $state = $store->update(static fn (RuntimeState $before): RuntimeState => new RuntimeState([
      'threshold_ms' => 42.0, 'runtime_id' => $before->runtimeId, 'version' => $before->version + 1,
    ]));
    self::assertSame(42.0, $state->thresholdMs);
  }

  /** @return void 争锁失败会有界退出，不让管理调用永久等待 */
  public function testContentionHasBoundedWait(): void
  {
    $store = new SharedStateStore(1000, 4);
    $lock = $this->lock($store);
    $holder = new Process(static function (Process $process) use ($lock): void {
      $lock->acquire();
      $process->write('locked');
      $process->setTimeout(1);
      $message = $process->read();
      $lock->release();
      $process->exit($message === 'release' ? 0 : 1);
    }, false, SOCK_STREAM, false);
    self::assertNotFalse($holder->start());
    self::assertSame('locked', $holder->read());
    $errorMessage = null;
    try { $store->read(); }
    catch (\RuntimeException $error) { $errorMessage = $error->getMessage(); }
    finally { $holder->write('release'); }
    $status = Process::wait(true);
    self::assertSame(0, $status['code']);
    self::assertSame('监控运行配置争锁超时（100ms）', $errorMessage);
  }

  /** @return void 同一进程也不能重复取得同一互斥量，避免 flock 描述符可重入漏洞 */
  public function testMutexIsNotReentrant(): void
  {
    $lock = new ProcessMutex();
    self::assertTrue($lock->tryAcquire());
    self::assertFalse($lock->tryAcquire());
    $lock->release();
    self::assertTrue($lock->tryAcquire());
    $lock->release();
  }

  /**
   * 取得真实共享互斥量用于构造争锁
   * @param SharedStateStore $store 被测存储
   * @return ProcessMutex 真实锁
   */
  private function lock(SharedStateStore $store): ProcessMutex
  {
    return (new ReflectionProperty($store, 'lock'))->getValue($store);
  }
}
