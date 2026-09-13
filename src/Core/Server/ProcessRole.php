<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Core\Server;

/**
 * 进程角色标记
 *
 * 标记当前进程在 Swoole 多进程模型中的角色，供连接池等资源管理者
 * 判定"连接归谁所有"：
 *
 * - CLI：普通命令行进程（server:start 之前 / 自定义命令），池行为与单进程一致；
 * - MASTER：server:start 之后、workerStart 之前的进程上下文。master 本体
 *   与 fork 出的 manager 进程均处于此角色（manager 不触发 workerStart），
 *   此阶段的连接一律走一次性短连接（用完即毁），防止污染 worker 连接池；
 * - WORKER：workerStart 之后的工作进程/任务进程，连接池的合法归属者。
 *
 * 角色流转：CLI --Server::start()--> MASTER --workerStart--> WORKER。
 * 服务停止后角色不再回退：master 进程收尾阶段的 Db/Cache 调用仍走短连接，
 * 属安全兜底（非协程下本就直接建连直关，行为等价）。
 */
final class ProcessRole
{
  /** @var int 普通命令行进程（默认角色） */
  public const int CLI = 0;
  /** @var int master/manager 进程上下文（server 启动后、workerStart 前） */
  public const int MASTER = 1;
  /** @var int 工作进程/任务进程（workerStart 之后） */
  public const int WORKER = 2;
  /** @var int 当前进程角色 */
  private static int $role = self::CLI;

  /**
   * 标记当前进程进入 master/manager 角色
   *
   * 由 Server::start() 在 $server->start() 前调用；fork 出的 manager/worker
   * 进程在各自的 workerStart 触发前继承此角色。
   */
  public static function markAsMaster(): void
  {
    self::$role = self::MASTER;
  }

  /**
   * 标记当前进程为工作进程（worker/task worker）
   *
   * 由 ServerEventHook 内置 workerStart 回调自动调用；用户自定义的
   * Swoole\Process 子进程不触发 workerStart，如需使用连接池可在进程
   * 回调首行手动调用本方法。
   */
  public static function markAsWorker(): void
  {
    self::$role = self::WORKER;
  }

  /**
   * 判断当前是否处于 server 运行模式（master/worker 任意角色）
   *
   * @return bool server:start 之后返回 true，纯 CLI 进程返回 false
   */
  public static function isServerMode(): bool
  {
    return self::$role !== self::CLI;
  }

  /**
   * 判断当前是否为工作进程/任务进程
   *
   * @return bool workerStart 之后返回 true
   */
  public static function isWorker(): bool
  {
    return self::$role === self::WORKER;
  }
}
