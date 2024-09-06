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

namespace Viswoole\Core\Facade;

use Closure;
use Override;
use Viswoole\Core\Facade;

/**
 * 中间件管理器
 *
 * @method static void register(Closure|string $handler, string $server = null) 注册中间件
 * @method static mixed process(callable|array|string $handler, array $middlewares = []) 运行中间件
 */
class Middleware extends Facade
{

  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Core\Middleware::class;
  }
}
