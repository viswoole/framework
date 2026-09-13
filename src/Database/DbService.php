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

namespace Viswoole\Database;


use Override;
use Throwable;
use function config;
use Viswoole\Core\Service\Provider;
use Viswoole\Core\Server\ServerEventHook;
use Viswoole\Database\Transaction\XaRecovery;
use function echo_log;

/**
 * 数据库服务提供者
 *
 * 负责将数据库管理器注册到应用容器，并在所有系统服务绑定完毕后初始化数据库通道。
 *
 * @see DbManager
 */
class DbService extends Provider
{

  /**
   * 所有系统服务绑定完毕后调用，初始化数据库通道管理器
   */
  #[Override] public function boot(): void
  {
    // 创建数据库通道管理器
    $this->app->make('db');
    // XA 自动恢复默认关闭：未使用 XA 的项目零开销、零噪音（不建表、不探测），
    // 悬挂事务经 php viswoole xa:recover 手动/定时收敛；显式开启后才注册钩子。
    // 仅 0 号 worker 实际执行恢复：N 个 worker 各自启动会触发 N 次钩子，
    // 重复扫描/并发终结虽被幂等设计吸收，但均为重复功——单点执行即可收敛，
    // 且 reload 全量重启时 0 号必然重启，恢复不丢失。
    // 必须挂在 workerStart（连接池随 worker 进程独立创建，master 阶段
    // 创建的连接无法安全共享给 fork 出的 worker）。
    if (!config('database.xa.auto_recovery', false)) return;
    ServerEventHook::addEvent('workerStart', function (?\Swoole\Server $server, int $workerId): void {
      if ($workerId !== 0) return;
      try {
        XaRecovery::run();
      } catch (Throwable $e) {
        // 恢复失败不阻断 worker 启动：残留 journal 行保留，下次启动/重试继续收敛
        echo_log('XA 崩溃恢复任务执行失败：' . $e->getMessage(), 'XA', backtrace: 0);
      }
    });
  }

  /**
   * 注册数据库管理器到服务容器
   */
  #[Override] public function register(): void
  {
    /**
     * 绑定数据库通道管理器
     */
    $this->app->bind('db', DbManager::class);
  }
}
