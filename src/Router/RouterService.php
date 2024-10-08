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
use Viswoole\Core\Service\Provider;
use Viswoole\Router\Commands\ClearCache;

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
    // 监听服务创建前事件
    $this->app->event->on('CreateServerBefore', function () {
      $this->app->make('router');
    });
  }

  /**
   * @inheritDoc
   */
  #[Override] public function register(): void
  {
    $this->app->bind('router', Router::class);
    $this->app->console->addCommand(new ClearCache);
  }
}
