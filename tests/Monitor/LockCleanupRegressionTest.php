<?php

declare(strict_types=1);

/* 职责：用真实 creator/worker 进程验证启动清扫，不能误删空闲活跃实例。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Process;
use Swoole\Runtime;
use Viswoole\Monitor\Runtime\ProcessMutex;

/** worker 尚未打开互斥句柄时也必须受存活租约保护。 */
final class LockCleanupRegressionTest extends TestCase
{
  /** @return iterable<string,array{int}> 覆盖在无 Hook 及已有 Hook 的进程中初始化 */
  public static function provideCreationHooks(): iterable
  {
    yield 'native' => [0];
    yield 'hook-all' => [SWOOLE_HOOK_ALL];
  }

  /**
   * creator 强杀且没有 worker 后，新实例启动应清扫遗留锁文件
   * @param int $hookFlags creator 初始化时的 Hook
   * @return void
   */
  #[DataProvider('provideCreationHooks')]
  public function testNextStartupSweepsKilledCreatorWithoutWorkers(int $hookFlags): void
  {
    [$creator, $path] = $this->spawnCreator(false, false, $hookFlags);
    $status = Process::wait(true);
    self::assertSame(SIGKILL, $status['signal']);
    self::assertFileExists($path);
    $replacement = new ProcessMutex();
    self::assertFileDoesNotExist($path);
    self::assertTrue($replacement->tryAcquire());
    $replacement->release();
  }

  /**
   * creator 死亡但空闲 worker 存活时必须保留，最后 worker 退出后才清扫
   * @param int $hookFlags creator 初始化时的 Hook
   * @return void
   */
  #[DataProvider('provideCreationHooks')]
  public function testSurvivingWorkerProtectsLockEvenBeforeFirstAcquire(int $hookFlags): void
  {
    foreach ([false, true] as $isGraceful) {
      [$creator, $path, $workerPid] = $this->spawnCreator(true, $isGraceful, $hookFlags);
      $status = Process::wait(true);
      self::assertSame($isGraceful ? 0 : SIGKILL, $status['signal']);
      try {
        $replacement = new ProcessMutex();
        self::assertFileExists($path, 'creator 已死不能作为删除活跃 worker 锁文件的依据');
        $creator->write('probe');
        self::assertSame('ok', $creator->read());
      } finally {
        Process::kill($workerPid, SIGKILL);
      }
      // 隔离测试容器中 PHPUnit 是 PID 1，会接管 creator 的孤儿 worker，必须回收。
      if ((int)getmypid() === 1) {
        $workerStatus = Process::wait(true);
        self::assertSame($workerPid, $workerStatus['pid']);
      }
      $deadline = hrtime(true) + 1_000_000_000;
      do {
        $replacement = new ProcessMutex();
        clearstatcache(true, $path);
        if (!is_file($path)) { break; }
        usleep(1000);
      } while (hrtime(true) < $deadline);
      self::assertFileDoesNotExist($path);
    }
  }

  /** @return void 活跃但空闲的实例不能被并行启动清扫，旧格式散文件也不能擅删 */
  public function testIdleInstanceAndUnverifiableLegacyFileArePreserved(): void
  {
    $active = new ProcessMutex();
    $path = (new ReflectionProperty($active, 'path'))->getValue($active);
    $legacy = tempnam(sys_get_temp_dir(), 'viswoole-monitor-legacy-');
    try {
      $replacement = new ProcessMutex();
      self::assertFileExists($path);
      self::assertFileExists($legacy);
      self::assertTrue($active->tryAcquire());
      $active->release();
    } finally { unlink($legacy); }
  }

  /**
   * 创建会自杀的 creator，worker 通过继承的管道与测试主进程通信
   * @param bool $withWorker 是否留下活跃 worker
   * @param bool $isGraceful creator 是否正常销毁对象后退出
   * @param int $hookFlags creator 创建锁前的 Hook
   * @return array{Process,string,?int} 管道、锁路径及 worker PID
   */
  private function spawnCreator(bool $withWorker, bool $isGraceful = false, int $hookFlags = 0): array
  {
    $creator = new Process(static function (Process $process) use ($withWorker, $isGraceful, $hookFlags): void {
      Runtime::setHookFlags($hookFlags);
      $mutex = new ProcessMutex();
      $path = (new ReflectionProperty($mutex, 'path'))->getValue($mutex);
      if ($withWorker) { self::startWorker($process, $mutex, $path); }
      else { $process->write(json_encode(['path' => $path, 'worker_pid' => null], JSON_THROW_ON_ERROR)); }
      if ($isGraceful) { unset($mutex); $process->exit(0); }
      Process::kill((int)getmypid(), SIGKILL);
    }, false, SOCK_STREAM, false);
    self::assertNotFalse($creator->start());
    $creator->setTimeout(2);
    $data = json_decode($creator->read(), true, flags: JSON_THROW_ON_ERROR);
    return [$creator, $data['path'], $data['worker_pid']];
  }

  /**
   * 派生尚未取得互斥量的 worker，证明保护不依赖一次 acquire
   * @param Process $creator creator 的继承管道
   * @param ProcessMutex $mutex 继承的实例资源
   * @param string $path 已注册路径
   * @return void
   */
  private static function startWorker(Process $creator, ProcessMutex $mutex, string $path): void
  {
    $worker = new Process(static function (Process $process) use ($creator, $mutex, $path): void {
      $creator->write(json_encode(['path' => $path, 'worker_pid' => (int)getmypid()], JSON_THROW_ON_ERROR));
      if ($creator->read() !== 'probe') { $process->exit(1); }
      $mutex->acquire();
      $mutex->release();
      $creator->write('ok');
      $creator->read();
      $process->exit(0);
    }, false, 0, false);
    if ($worker->start() === false) { throw new \RuntimeException('清扫测试 worker 启动失败'); }
  }
}
