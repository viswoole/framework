<?php

declare(strict_types=1);

/* 职责：提供实例私有、跨 fork 且进程退出自动释放的有界协作互斥。 */

namespace Viswoole\Monitor\Runtime;

use RuntimeException;
use Swoole\Coroutine;

/** 互斥句柄每进程重开，存活租约持续继承；文件不存配置。 */
final class ProcessMutex
{
  private const int TIMEOUT_NS = 100_000_000;
  private string $path;
  private LockRegistry $registry;
  private LockLease $lease;
  private int $handlePid = 0;
  private bool $isHeld = false;
  /** @var resource|null 当前进程独立描述符，不能直接复用 fork 继承的描述符 */
  private mixed $handle = null;

  /**
   * 主进程注册存活租约并创建私有互斥文件
   * @param LockRegistry|null $registry 可替换的测试注册目录
   * @throws RuntimeException 本地锁资源初始化失败
   */
  public function __construct(?LockRegistry $registry = null)
  {
    $this->registry = $registry ?? new LockRegistry();
    $this->lease = $this->registry->create();
    $this->path = $this->lease->directory . '/mutex.lock';
  }

  /**
   * 在有限时间内取得互斥量，等待时不冻结协程事件循环
   * @return void
   * @throws RuntimeException 100ms 内无法取得锁
   */
  public function acquire(): void
  {
    $deadline = hrtime(true) + self::TIMEOUT_NS;
    do {
      if ($this->tryAcquire()) { return; }
      if (hrtime(true) >= $deadline) { throw new RuntimeException('监控运行配置争锁超时（100ms）'); }
      if (Coroutine::getCid() >= 0) { Coroutine::sleep(0.001); }
      else { usleep(1000); }
    } while (true);
  }

  /**
   * 非阻塞尝试；进程切换后先重开句柄，避免 flock 继承语义破坏互斥
   * @return bool 是否取得锁
   * @throws RuntimeException 文件无法打开
   */
  public function tryAcquire(): bool
  {
    $pid = (int)getmypid();
    if ($pid !== $this->handlePid) {
      if (is_resource($this->handle)) { NativeLockStream::close($this->handle); }
      $handle = NativeLockStream::open($this->path, 'r+');
      $this->handle = $handle;
      $this->handlePid = $pid;
      $this->isHeld = false;
    }
    // flock 对同一描述符可重入；显式保留原互斥量的非重入语义。
    if ($this->isHeld) { return false; }
    $this->isHeld = NativeLockStream::lock($this->handle, LOCK_EX | LOCK_NB);
    return $this->isHeld;
  }

  /**
   * 释放当前进程持有的锁
   * @return void
   * @throws RuntimeException 内核释放失败
   */
  public function release(): void
  {
    // fork 会复制本进程状态；子进程无权释放父进程仍持有的同一打开文件描述。
    if (
      $this->handlePid !== (int)getmypid() || !$this->isHeld || !is_resource($this->handle)
      || !NativeLockStream::lock($this->handle, LOCK_UN)
    ) {
      throw new RuntimeException('监控运行配置解锁失败');
    }
    $this->isHeld = false;
  }

  /**
   * 关闭互斥及存活句柄，再清扫已无使用者的实例
   * @return void
   */
  public function __destruct()
  {
    try {
      if (is_resource($this->handle)) { NativeLockStream::close($this->handle); }
      if (!isset($this->lease)) { return; }
      $this->lease->close();
      $this->registry->sweep();
    } catch (\Throwable $error) {
      trigger_error('监控锁资源清理失败: ' . $error::class, E_USER_WARNING);
    }
  }
}
