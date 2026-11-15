<?php

declare(strict_types=1);

/* 职责：验证 fork 后子进程不能通过关闭或解锁继承句柄影响父进程互斥。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Process;
use Swoole\Runtime;
use Viswoole\Monitor\Runtime\ProcessMutex;

final class InheritedMutexRegressionTest extends TestCase
{
  /** @return void Hook 下重开子进程句柄仍须等待父进程显式解锁 */
  public function testChildCannotReleaseInheritedParentLock(): void
  {
    $originalFlags = Runtime::getHookFlags();
    Runtime::setHookFlags(SWOOLE_HOOK_ALL);
    $mutex = new ProcessMutex();
    try {
      $mutex->acquire();
      $worker = $this->startContender($mutex);
      self::assertSame('blocked', $worker->read());
      $mutex->release();
      $worker->write('released');
      self::assertSame('acquired', $worker->read());
      $status = Process::wait(true);
      self::assertSame(0, $status['code']);
      self::assertTrue($mutex->tryAcquire());
      $mutex->release();
    } finally {
      unset($worker, $mutex);
      Runtime::setHookFlags($originalFlags);
    }
  }

  /**
   * 子进程先尝试错误解锁，再关闭继承句柄并重开独立句柄争锁
   * @param ProcessMutex $mutex 父进程已持有的互斥量
   * @return Process 已启动的有限等待进程
   */
  private function startContender(ProcessMutex $mutex): Process
  {
    $worker = new Process(static function (Process $process) use ($mutex): void {
      $process->setTimeout(3);
      try {
        $mutex->release();
        $process->write('incorrectly released');
        return;
      } catch (\RuntimeException $error) {
        if ($error->getMessage() !== '监控运行配置解锁失败') { throw $error; }
      }
      $process->write($mutex->tryAcquire() ? 'incorrectly acquired' : 'blocked');
      if ($process->read() !== 'released') { throw new \RuntimeException('未收到父进程解锁通知'); }
      $mutex->acquire();
      $mutex->release();
      $process->write('acquired');
    }, false, SOCK_STREAM, true);
    self::assertNotFalse($worker->start());
    $worker->setTimeout(3);
    return $worker;
  }
}
