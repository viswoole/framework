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

namespace Viswoole\Router\Facade;

use Viswoole\Core\Facade;
use Viswoole\Router\RouteCollector;

/**
 * 路由管理器
 *
 * @method static RouteCollector collector(?string $serverName = null) 获取路由控制器实例
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Core\\Facades\\Router
 */
class Router extends Facade
{

  /**
   * @inheritDoc
   */
  #[\Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Router\RouterManager::class;
  }
}