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

declare (strict_types=1);

namespace Viswoole\Core\Service;

use Override;
use Viswoole\Core\Server\TaskManager;

/**
 * 任务管理服务提供者，负责注册和启动任务管理器
 */
class TaskService extends Provider
{

  /**
   * 启动任务管理器，初始化任务队列与事件监听
   */
  #[Override] public function boot(): void
  {
    // 启动任务管理器
    $this->app->make('task');
  }

  /**
   * 将任务管理器绑定到容器
   */
  #[Override] public function register(): void
  {
    $this->app->bind('task', TaskManager::class);
  }
}
