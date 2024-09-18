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

namespace Viswoole\Router\Route;

use InvalidArgumentException;

/**
 * 路由项
 */
class RouteItem extends BaseRoute
{
  protected string $type = 'item';

  /**
   * @param string|array $paths 路由访问路径
   * @param callable|string|array $handler 路由处理函数
   * @param BaseRoute|null $parentOption 父级路由配置
   * @param string|null $id
   */
  public function __construct(
    array|string          $paths,
    callable|array|string $handler,
    BaseRoute             $parentOption = null,
    string                $id = null
  )
  {
    if (empty($paths)) {
      throw new InvalidArgumentException('route item paths is empty');
    }
    parent::__construct($paths, $handler, $parentOption, $id);
  }
}
