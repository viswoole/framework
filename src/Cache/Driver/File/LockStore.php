<?php

declare(strict_types=1);

namespace Viswoole\Cache\Driver\File;

use Swoole\Coroutine;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Core\Common\Filesystem;
use Viswoole\Core\Exception\FilesystemException;

/**
 * 文件租约存储：仅在更新租约时持有 flock，不管理业务调用方生命周期。
 * 锁文件必须永久保留在驱动的受保护区域，避免删除 inode 破坏互斥。
 */
final class LockStore
{
  private readonly LeaseRecord $records;

  public function __construct(private readonly string $directory)
  {
    $this->records = new LeaseRecord();
  }

  /**
   * 原子创建租约或接管已过期租约。
   *
   * @param string $scene 已包含缓存前缀的场景
   * @param string $id 持有者标识
   * @param int $expire 租约秒数，非正数表示永不过期
   * @return bool 是否取得租约
   */
  public function acquire(string $scene, string $id, int $expire): bool
  {
    $handle = $this->openLocked($scene);
    if ($handle === false) return false;
    try {
      $filename = $this->filename($scene) . '.json';
      $record = $this->records->read($filename);
      if ($record !== null && ($record['expiresAt'] === 0 || $record['expiresAt'] > microtime(true))) {
        return false;
      }
      // 到成功取得互斥量时才计算期限，重试等待不消耗租约。
      $this->records->write($filename, json_encode([
        'id' => $id,
        'expiresAt' => $expire > 0 ? microtime(true) + $expire : 0
      ], JSON_THROW_ON_ERROR));
      return true;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  /**
   * 原子校验持有者并释放租约，过期持有者不能删除后继租约。
   *
   * @param string $scene 已包含缓存前缀的场景
   * @param string $id 持有者标识
   * @return bool 是否释放匹配的租约
   */
  public function release(string $scene, string $id): bool
  {
    $handle = $this->openLocked($scene, true);
    try {
      $filename = $this->filename($scene) . '.json';
      $record = $this->records->read($filename);
      if ($record === null || $record['id'] !== $id) return false;
      $this->records->delete($filename);
      return true;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  /**
   * 打开稳定互斥文件；释放租约时等待短期占用结束。
   *
   * @param string $scene 锁场景
   * @param bool $isWaiting 是否等待互斥量
   * @return resource|false 已加锁句柄，非等待模式竞争失败返回 false
   */
  private function openLocked(string $scene, bool $isWaiting = false): mixed
  {
    try {
      Filesystem::ensureDirectory($this->directory);
    } catch (FilesystemException $exception) {
      throw new CacheErrorException('创建文件租约目录失败：' . $this->directory, previous: $exception);
    }
    $filename = $this->filename($scene);
    $handle = @fopen($filename, 'c+');
    if ($handle === false) throw new CacheErrorException('打开文件租约失败：' . $filename);
    $isLocked = false;
    try {
      $isLocked = $this->acquireMutex($handle, $isWaiting);
      return $isLocked ? $handle : false;
    } finally {
      if (!$isLocked) fclose($handle);
    }
  }

  /**
   * 等待短期互斥量时主动让出协程，避免阻塞持有者所属 Worker。
   *
   * @param resource $handle 互斥文件句柄
   * @param bool $isWaiting 是否等待互斥量
   * @return bool 是否已取得互斥量
   */
  private function acquireMutex(mixed $handle, bool $isWaiting): bool
  {
    while (true) {
      if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) return true;
      if (!$wouldBlock) throw new CacheErrorException('获取文件租约互斥量失败');
      if (!$isWaiting) return false;
      // 析构路径同样等待，不能把自动解锁的重试责任留给已销毁对象。
      if (Coroutine::getCid() >= 0) Coroutine::sleep(0.001);
      else usleep(1000);
    }
  }

  /**
   * 场景仅用于散列，不允许业务键或前缀成为文件路径。
   *
   * @param string $scene 已包含缓存前缀的场景
   * @return string 永久保留的互斥文件路径
   */
  private function filename(string $scene): string
  {
    return $this->directory . '/' . hash('sha256', $scene);
  }
}
