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

use Closure;
use ViSwoole\Core\App;

/**
 * 路由未匹配处理
 */
readonly class RouteMiss
{
  public function __construct(
    public Closure $handler
  )
  {
  }

  /**
   * 执行路由未匹配处理
   *
   * @return mixed
   */
  public function handler(): mixed
  {
    return App::factory()->invokeFunction($this->handler);
  }
}
