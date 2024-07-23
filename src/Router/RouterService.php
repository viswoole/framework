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

namespace Viswoole\Router;

use Override;
use Viswoole\Core\Server;
use Viswoole\Core\Service\Provider;

/**
 * 路由服务注册
 */
class RouterService extends Provider
{
  /**
   * @inheritDoc
   */
  #[Override] public function boot(): void
  {
    // 监听服务启动之前
    $this->app->event->on('ServerCreate', function (Server $server) {
      $this->app->make('router');
    });
  }

  /**
   * @inheritDoc
   */
  #[Override] public function register(): void
  {
    $this->app->bind('router', RouterManager::class);
  }
}
