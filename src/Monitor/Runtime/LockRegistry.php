<?php

declare(strict_types=1);

/* 职责：在账户私有目录创建及清扫实例锁资源，清扫依据内核存活锁而非 PID。 */

namespace Viswoole\Monitor\Runtime;

use RuntimeException;
use Swoole\Coroutine;
use Throwable;

/** registry.lock 永不删除，串行化初始化与清扫，避免检查和删除之间出现新使用者。 */
final class LockRegistry
{
  private string $root;

  /**
   * 初始化账户私有注册目录
   * @param string|null $root 测试可指定独立目录，默认按当前账户隔离
   * @throws RuntimeException 目录不安全或不可创建
   */
  public function __construct(?string $root = null)
  {
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $this->root = $root ?? sys_get_temp_dir() . '/viswoole-monitor-locks-' . $uid;
    if (!is_dir($this->root) && !@mkdir($this->root, 0700) && !is_dir($this->root)) {
      throw new RuntimeException('监控锁注册目录创建失败');
    }
    if (is_link($this->root) || fileowner($this->root) !== $uid || (fileperms($this->root) & 0077) !== 0) {
      throw new RuntimeException('监控锁注册目录必须为当前账户私有目录且不能是符号链接');
    }
  }

  /**
   * 启动时清扫遗留实例并原子注册新存活租约
   * @return LockLease 已持有共享存活锁的实例资源
   */
  public function create(): LockLease
  {
    return $this->synchronized(function (): LockLease {
      $this->sweepUnused();
      $directory = $this->root . '/instance-' . bin2hex(random_bytes(16));
      if (!mkdir($directory, 0700)) { throw new RuntimeException('监控实例锁目录创建失败'); }
      $handle = null;
      try {
        $handle = $this->createFile($directory . '/lease.lock');
        if (!NativeLockStream::lock($handle, LOCK_SH | LOCK_NB)) { throw new RuntimeException('监控实例存活锁初始化失败'); }
        $mutex = $this->createFile($directory . '/mutex.lock');
        NativeLockStream::close($mutex);
        return new LockLease($directory, $handle);
      } catch (Throwable $error) {
        if (is_resource($handle)) { NativeLockStream::close($handle); }
        $this->removeUnused($directory);
        throw $error;
      }
    });
  }

  /**
   * 仅清理已无任何租约描述符的实例，活跃但空闲的实例也会保留
   * @return int 清理数量
   */
  public function sweep(): int
  {
    return $this->synchronized(fn (): int => $this->sweepUnused());
  }

  /**
   * 对注册和清扫加同一固定内核锁；回调仅为内部文件操作
   * @param callable():LockLease|int $action 内部同步操作
   * @return LockLease|int 操作结果
   */
  private function synchronized(callable $action): LockLease|int
  {
    $path = $this->root . '/registry.lock';
    if (is_link($path)) { throw new RuntimeException('监控注册锁不能是符号链接'); }
    $handle = NativeLockStream::open($path, 'c+');
    try {
      $this->acquireGate($handle);
      try { return $action(); }
      finally {
        if (!NativeLockStream::lock($handle, LOCK_UN)) { throw new RuntimeException('监控注册锁释放失败'); }
      }
    } finally { NativeLockStream::close($handle); }
  }

  /**
   * 注册仅在启动/资源销毁发生；有限等待避免冻结协程
   * @param resource $handle 注册锁描述符
   * @return void
   * @throws RuntimeException 一秒内未能取得注册锁
   */
  private function acquireGate(mixed $handle): void
  {
    $deadline = hrtime(true) + 1_000_000_000;
    while (!NativeLockStream::lock($handle, LOCK_EX | LOCK_NB)) {
      if (hrtime(true) >= $deadline) { throw new RuntimeException('监控锁资源注册等待超时（1s）'); }
      if (Coroutine::getCid() >= 0) { Coroutine::sleep(0.001); }
      else { usleep(1000); }
    }
  }

  /** @return int 注册锁内扫描，初始化中的实例不可能被误判为遗留实例 */
  private function sweepUnused(): int
  {
    $directories = glob($this->root . '/instance-*', GLOB_ONLYDIR);
    if ($directories === false) { throw new RuntimeException('监控实例锁目录扫描失败'); }
    $removed = 0;
    foreach ($directories as $directory) {
      if ($this->removeUnused($directory)) { $removed++; }
    }
    return $removed;
  }

  /**
   * 独占租约成功才可删除，缺少证明或出现未知文件时保守跳过
   * @param string $directory 已验证注册根目录下的实例目录
   * @return bool 是否删除
   */
  private function removeUnused(string $directory): bool
  {
    if (is_link($directory) || preg_match('/^instance-[a-f0-9]{32}$/D', basename($directory)) !== 1) { return false; }
    $entries = scandir($directory);
    if ($entries === false) { throw new RuntimeException('监控实例锁目录读取失败'); }
    $files = array_values(array_diff($entries, ['.', '..']));
    if ($files === []) {
      if (!rmdir($directory)) { throw new RuntimeException('监控未完成实例目录清理失败'); }
      return true;
    }
    if (array_diff($files, ['lease.lock', 'mutex.lock']) !== [] || !in_array('lease.lock', $files, true)) { return false; }
    foreach ($files as $file) {
      if (is_link($directory . '/' . $file) || !is_file($directory . '/' . $file)) { return false; }
    }
    $handle = NativeLockStream::open($directory . '/lease.lock', 'r+');
    try {
      if (!NativeLockStream::lock($handle, LOCK_EX | LOCK_NB)) { return false; }
      return $this->removeFiles($directory, $files);
    } finally { NativeLockStream::close($handle); }
  }

  /**
   * 已持有独占存活锁和注册锁时删除已知文件
   * @param string $directory 实例目录
   * @param list<string> $files 已验证文件名
   * @return bool 是否完成删除
   */
  private function removeFiles(string $directory, array $files): bool
  {
    // 租约最后删除，意外中断时不会留下无租约的互斥文件。
    sort($files, SORT_STRING);
    foreach (array_reverse($files) as $file) {
      if (!unlink($directory . '/' . $file)) { throw new RuntimeException('监控遗留锁文件清理失败'); }
    }
    if (!rmdir($directory)) { throw new RuntimeException('监控遗留实例目录清理失败'); }
    return true;
  }

  /**
   * 创建私有空文件，不覆盖任何既有路径
   * @param string $path 新文件路径
   * @return resource 新描述符
   */
  private function createFile(string $path): mixed
  {
    $handle = NativeLockStream::open($path, 'x+');
    if (!chmod($path, 0600)) { NativeLockStream::close($handle); throw new RuntimeException('监控锁文件权限设置失败'); }
    return $handle;
  }
}
