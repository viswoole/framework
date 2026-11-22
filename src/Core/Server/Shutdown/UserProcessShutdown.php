<?php

declare(strict_types=1);

/* 职责：保存单个 Swoole 用户进程槽位跨进程共享的停机确认。 */

namespace Viswoole\Core\Server\Shutdown;

use Swoole\Atomic;

/**
 * 用户进程排空回执
 */
final readonly class UserProcessShutdown
{
  /**
   * @var Atomic 当前实例是否已确认服务停机
   */
  private Atomic $acknowledged;

  /**
   * 分配共享确认状态
   *
   * 在用户进程 fork 前创建，初始状态没有任何退出证据。
   */
  public function __construct()
  {
    $this->acknowledged = new Atomic();
  }

  /**
   * 重置新进程实例的确认状态
   *
   * 子进程入口调用，避免 Manager 拉起的替代实例沿用前一实例的确认。
   *
   * @return void 初始状态为待确认
   */
  public function started(): void
  {
    $this->acknowledged->set(0);
  }

  /**
   * 确认停机排空
   *
   * 用户进程完成在途工作并结束清理后调用。单独停止该进程时无需修改整服停机回执。
   *
   * @return void 服务停机期间记录确认
   */
  public function acknowledge(): void
  {
    if (ShutdownRuntime::isStopping()) {
      $this->acknowledged->set(1);
    }
  }

  /**
   * 查询排空确认
   *
   * 主进程在收束进程树后读取，避免将进程消失视为工作已完成。
   *
   * @return bool 当前实例已确认排空
   */
  public function isAcknowledged(): bool
  {
    return $this->acknowledged->get() === 1;
  }
}
