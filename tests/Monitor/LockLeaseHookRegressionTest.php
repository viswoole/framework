<?php

declare(strict_types=1);

/* 职责：验证STDIO Hook下关闭fork继承的存活句柄不会解除其他进程的租约。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Process;
use Swoole\Runtime;
use Viswoole\Monitor\Runtime\LockRegistry;
use Viswoole\Monitor\Runtime\ProcessMutex;

/** 不能只验证无Hook的普通fork，实际服务使用HOOK_ALL。 */
final class LockLeaseHookRegressionTest extends TestCase
{
  /** @return iterable<string,array{int,int,bool}> 覆盖流创建及关闭时的 Hook 独立组合 */
  public static function provideHookLifecycles(): iterable
  {
    $modes = ['native' => 0, 'stdio' => SWOOLE_HOOK_STDIO, 'file' => SWOOLE_HOOK_FILE, 'all' => SWOOLE_HOOK_ALL];
    foreach ($modes as $creationName => $creationFlags) {
      foreach ($modes as $closeName => $closeFlags) {
        foreach ([false, true] as $isCoroutine) {
          yield $creationName . '-' . $closeName . '-' . (int)$isCoroutine => [$creationFlags, $closeFlags, $isCoroutine];
        }
      }
    }
  }

  /**
   * 创建与关闭的 Hook 顺序不应影响其他 fork 使用者
   * @param int $creationFlags 创建文件流时的 Hook
   * @param int $closeFlags 子进程关闭时的 Hook
   * @param bool $isCoroutine 是否在协程内关闭
   * @return void
   */
  #[DataProvider('provideHookLifecycles')]
  public function testHookLifecyclePreservesLease(int $creationFlags, int $closeFlags, bool $isCoroutine): void
  {
    $originalFlags = Runtime::getHookFlags();
    $root = sys_get_temp_dir() . '/viswoole-hook-lifecycle-' . bin2hex(random_bytes(8));
    $registry = new LockRegistry($root);
    $mutex = null;
    try {
      Runtime::setHookFlags($creationFlags);
      $mutex = new ProcessMutex($registry);
      self::assertSame($creationFlags, Runtime::getHookFlags());
      $worker = $this->startLeaseProbe($mutex, $registry, [$closeFlags, $isCoroutine]);
      $result = json_decode($worker->read(), true, flags: JSON_THROW_ON_ERROR);
      $status = Process::wait(true);
      self::assertSame(0, $status['code']);
      self::assertSame(0, $result['swept'], '关闭本进程副本不能解除其他进程的存活锁');
      self::assertTrue($result['restored']);
      self::assertTrue($mutex->tryAcquire());
      $mutex->release();
      self::assertSame($creationFlags, Runtime::getHookFlags());
    } finally {
      unset($worker, $mutex);
      $registry->sweep();
      unlink($root . '/registry.lock');
      rmdir($root);
      Runtime::setHookFlags($originalFlags);
    }
  }

  /**
   * 子进程重复关闭自己的租约副本，再探测目录能否被清扫
   * @param ProcessMutex $mutex 继承的锁资源
   * @param LockRegistry $registry 实例注册目录
   * @param array{int,bool} $options 关闭 Hook 与协程开关
   * @return Process 已启动且有读超时的探测进程
   */
  private function startLeaseProbe(ProcessMutex $mutex, LockRegistry $registry, array $options): Process
  {
    [$closeFlags, $isCoroutine] = $options;
    $worker = new Process(static function (Process $process) use ($mutex, $registry, $closeFlags): void {
      Runtime::setHookFlags($closeFlags);
      $lease = (new ReflectionProperty($mutex, 'lease'))->getValue($mutex);
      $lease->close();
      $lease->close();
      $process->write(json_encode([
        'swept' => $registry->sweep(),
        'restored' => Runtime::getHookFlags() === $closeFlags,
      ], JSON_THROW_ON_ERROR));
    }, false, SOCK_STREAM, $isCoroutine);
    self::assertNotFalse($worker->start());
    $worker->setTimeout(3);
    return $worker;
  }

  /**
   * Hook下关闭继承句柄保留父进程存活锁
   *
   * 子进程关闭自己的副本并触发清扫，父进程仍须正常取得互斥量。
   *
   * @return void 完成跨进程回归断言
   */
  public function testHookedClosePreservesOtherForkUsers(): void
  {
    $root = sys_get_temp_dir().'/viswoole-hook-lease-'.bin2hex(random_bytes(8));
    $registry = new LockRegistry($root);
    $mutex = new ProcessMutex($registry);
    $path = (new ReflectionProperty($mutex, 'path'))->getValue($mutex);
    $worker = new Process(static function (Process $process) use ($mutex, $registry): void {
      Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
      $flags = Runtime::getHookFlags();
      $lease = (new ReflectionProperty($mutex, 'lease'))->getValue($mutex);
      $lease->close();
      $swept = $registry->sweep();
      $process->write(json_encode(['swept' => $swept, 'restored' => Runtime::getHookFlags() === $flags], JSON_THROW_ON_ERROR));
    }, false, SOCK_STREAM, true);
    self::assertNotFalse($worker->start());
    $worker->setTimeout(3);
    $result = json_decode($worker->read(), true, flags: JSON_THROW_ON_ERROR);
    $status = Process::wait(true);
    self::assertSame(0, $status['code']);
    self::assertSame(0, $result['swept']);
    self::assertTrue($result['restored']);
    self::assertFileExists($path);
    self::assertTrue($mutex->tryAcquire());
    $mutex->release();
    unset($worker, $mutex);
    $registry->sweep();
    unlink($root.'/registry.lock');
    rmdir($root);
  }
}
