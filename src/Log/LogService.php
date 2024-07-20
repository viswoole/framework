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

namespace Viswoole\Log;

use Override;
use Viswoole\Core\Service\Provider;

/**
 * 日志服务
 */
class LogService extends Provider
{
  /**
   * @inheritDoc
   */
  #[Override] public function boot(): void
  {
    $this->app->make('log');
  }

  /**
   * @inheritDoc
   */
  #[Override] public function register(): void
  {
    $this->app->bind('log', LogManager::class);
  }
}
