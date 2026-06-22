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
use Viswoole\Core\Middleware;

/**
 * 中间件服务提供者，负责注册和启动中间件管理器
 */
class MiddlewareService extends Provider
{

  /**
   * 启动中间件管理器，解析并初始化所有已注册的中间件
   */
  #[Override] public function boot(): void
  {
    $this->app->make('middleware');
  }

  /**
   * 将中间件管理器绑定到容器
   */
  #[Override] public function register(): void
  {
    $this->app->bind('middleware', Middleware::class);
  }
}
