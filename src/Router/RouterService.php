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
use Viswoole\Core\FrameworkEvent;
use Viswoole\Core\Service\Provider;
use Viswoole\Router\Commands\ClearCache;

/**
 * 路由服务提供者，负责将 Router 注册到容器并在服务器启动前初始化路由
 */
class RouterService extends Provider
{
  /**
   * @inheritDoc
   */
  #[Override] public function boot(): void
  {
    // 监听服务创建前事件，延迟初始化路由
    $this->app->event->on(FrameworkEvent::ServerCreating, function () {
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
