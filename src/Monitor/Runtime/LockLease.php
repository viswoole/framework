<?php

declare(strict_types=1);

/* 职责：保留实例存活共享锁；fork 继承的描述符在最后一个使用进程退出后才释放。 */

namespace Viswoole\Monitor\Runtime;

use RuntimeException;

/** 只关闭本进程描述符，绝不 LOCK_UN，否则会解除所有 fork 继承者的共享锁。 */
final class LockLease
{
  /**
   * 接管已持有共享锁的句柄
   * @param string $directory 实例私有目录
   * @param resource $handle 已 LOCK_SH 的存活句柄，PHP 无 resource 属性类型
   */
  public function __construct(public readonly string $directory, private mixed $handle) {}

  /**
   * 关闭本进程副本，其他存活进程的副本继续保护目录
   * @return void
   * @throws RuntimeException 关闭句柄失败
   */
  public function close(): void
  {
    if (!is_resource($this->handle)) { return; }
    if (!fclose($this->handle)) { throw new RuntimeException('监控实例存活句柄关闭失败'); }
    $this->handle = null;
  }
}
