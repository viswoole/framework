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

use Closure;
use Override;
use Viswoole\Core\Facade;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由收集器
 *
 * @method static mixed dispatch(string $path, string $method, string $domain, ?array $params = null, ?callable $callback = null) 匹配路由，返回路由实例
 * @method static Route get(array|string $paths, callable|array|string $handler) 定义一个GET方式访问的路由
 * @method static Route add(array|string $paths, callable|array|string $handler, string $method) 添加路由
 * @method static Route post(array|string $paths, callable|array|string $handler) 定义一个POST方式访问的路由
 * @method static Route put(array|string $paths, callable|array|string $handler) 定义一个PUT方式访问的路由
 * @method static Route delete(array|string $paths, callable|array|string $handler) 定义一个DELETE方式访问的路由
 * @method static Route head(array|string $paths, callable|array|string $handler) 定义一个HEAD方式访问的路由
 * @method static Route options(array|string $paths, callable|array|string $handler) 定义一个OPTIONS方式访问的路由
 * @method static Route patch(array|string $paths, callable|array|string $handler) 定义一个PATCH方式访问的路由
 * @method static Route any(array|string $paths, callable|array|string $handler) 定义一个不限制访问方式的路由
 * @method static Group group(array|string $prefix, Closure $closure, string $id) 分组路由
 * @method static void miss(Closure $handler, array|string $method = '*') miss路由（在未匹配到路由的时候生效）
 * @method static void server(string $serverName, Closure $closure) 服务路由定义
 * @method static Route|Group getRoute(string $idOrCiteLink) 获取路由分组或路由对象，需传入路由id或完整引用链路
 * @method static Route[]|Group[] getRoutes() 获取所有路由列表
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Router\\Facade\\Router
 */
class Router extends Facade
{

  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Router\Router::class;
  }
}
