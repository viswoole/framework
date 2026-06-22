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
 * 路由门面，提供静态代理访问 Router 的所有方法
 *
 * @method static mixed dispatch(string $path, string $method, string $domain, ?array $params = null, ?callable $callback = null) 匹配请求路径到路由并执行处理函数
 * @method static Route get(array|string $paths, callable|array|string $handler) 定义 GET 方式访问的路由
 * @method static Route addRoute(array|string $paths, callable|array|string $handler, string $method) 添加自定义方法的路由
 * @method static Route post(array|string $paths, callable|array|string $handler) 定义 POST 方式访问的路由
 * @method static Route put(array|string $paths, callable|array|string $handler) 定义 PUT 方式访问的路由
 * @method static Route delete(array|string $paths, callable|array|string $handler) 定义 DELETE 方式访问的路由
 * @method static Route head(array|string $paths, callable|array|string $handler) 定义 HEAD 方式访问的路由
 * @method static Route options(array|string $paths, callable|array|string $handler) 定义 OPTIONS 方式访问的路由
 * @method static Route patch(array|string $paths, callable|array|string $handler) 定义 PATCH 方式访问的路由
 * @method static Route any(array|string $paths, callable|array|string $handler) 定义不限制访问方式的路由
 * @method static Group group(array|string $prefix, Closure $closure, string $id) 定义路由分组
 * @method static void miss(Closure $handler, array|string $method = '*') 定义兜底路由
 * @method static void server(string $serverName, Closure $closure) 定义服务级路由
 * @method static Route|Group getRoute(string $idOrCiteLink) 通过 ID 或引用链路获取路由实例
 * @method static array getRoutes() 获取所有路由列表
 * @method static bool isEnableApiDoc() 判断是否启用 API 文档解析功能
 * @method static array getApiList() 获取 API 文档列表
 * @method static array getApiDetail(string $citeLink) 获取指定路由的 API 文档详情
 *
 * @see \Viswoole\Router\Router 路由器
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
