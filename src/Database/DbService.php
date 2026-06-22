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

namespace Viswoole\Database;


use Override;
use Viswoole\Core\Service\Provider;

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
