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

use InvalidArgumentException;

/**
 * 路由线路
 */
class RouteItem extends RouteConfig
{
  /**
   * @inheritDoc
   */
  public function __construct(
    array|string          $paths,
    callable|array|string $handler,
    array                 $parentOption = null,
    string                $id = null
  )
  {
    if (empty($paths)) {
      throw new InvalidArgumentException('route item paths is empty');
    }
    parent::__construct($paths, $handler, $parentOption, $id);
  }

  /**
   * @inheritDoc
   */
  public function _register(RouteCollector $collector): void
  {
    $collector->registerRouteItem($this);
  }
}
