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
 * 数据库服务
 */
class DbService extends Provider
{

  /**
   * 该方法是在所有系统服务都绑定完毕过后调用，可以在此方法内注册路由，监听事件等
   *
   * @return void
   */
  #[Override] public function boot(): void
  {
    // 创建数据库通道管理器
    $this->app->make('db');
  }

  /**
   * 该方法会在服务注册时调用，在该方法内通过$this->app->bind('服务名', '服务类名');
   *
   * @return void
   */
  #[Override] public function register(): void
  {
    /**
     * 绑定数据库通道管理器
     */
    $this->app->bind('db', Db::class);
  }
}
