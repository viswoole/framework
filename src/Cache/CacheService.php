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

namespace Viswoole\Cache;

use Override;
use Viswoole\Core\Service\Provider;

/**
 * 缓存服务提供者，负责将缓存管理器注册到应用容器并完成引导启动
 *
 * 在服务注册阶段绑定 cache 标识到 CacheManager，在引导阶段触发缓存实例化。
 *
 * @see CacheManager
 */
class CacheService extends Provider
{
  /**
   * 引导阶段：触发缓存管理器实例化，确保配置在请求初期加载
   */
  #[Override] public function boot(): void
  {
    $this->app->make('cache');
  }

  /**
   * 注册阶段：将 cache 标识绑定到 CacheManager 类
   */
  #[Override] public function register(): void
  {
    $this->app->bind('cache', CacheManager::class);
  }
}
