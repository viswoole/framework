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

use Closure;
use RuntimeException;
use Viswoole\Core\Facade\Server;

/**
 * 路由方法
 */
abstract class Collector
{
  /**
   * @var array{string:RouteMiss} miss路由404
   */
  protected array $missRoutes = [];

  /**
   * @var RouteItem[]|RouteGroup[] 路由列表
   */
  protected array $routes = [];
  /**
   * @var RouteGroup|null 正在注册的路由组
   */
  protected ?RouteGroup $currentGroup = null;

  /**
   * 定义一个GET方式访问的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function get(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, 'GET');
  }

  /**
   * 添加路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @param string ...$method
   * @return RouteItem
   */
  public function add(
    string|array          $paths,
    string|array|callable $handler,
    string                ...$method,
  ): RouteItem
  {
    $route = new RouteItem(
      $paths,
      $handler,
      $this->currentGroup,
    );
    if (!empty($method)) $route->setMethod(...$method);
    $this->recordRouteItem($route);
    return $route;
  }

  /**
   * 记录路由
   *
   * @param BaseRoute $route
   * @return void
   */
  public function recordRouteItem(BaseRoute $route): void
  {
    if ($this->currentGroup === null) {
      $id = $route['id'];
      if (isset($this->routes[$id])) {
        $path = implode('|', $route['paths']);
        throw new RuntimeException("Route id:$id already exists,path:$path");
      }
      $this->routes[$id] = $route;
    } else {
      $this->currentGroup->addItem($route);
    }
  }

  /**
   * 定义一个POST方式访问的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function post(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, 'POST');
  }

  /**
   * 定义一个PUT方式访问的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function put(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, 'PUT');
  }

  /**
   * 定义一个DELETE方式访问的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function delete(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, 'DELETE');
  }

  /**
   * 定义一个HEAD方式访问的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function head(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, 'HEAD');
  }

  /**
   * 定义一个OPTIONS方式访问的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function options(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, 'OPTIONS');
  }

  /**
   * 定义一个PATCH方式访问的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function patch(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, 'PATCH');
  }

  /**
   * 定义一个不限制访问方式的路由
   *
   * @param string|array $paths
   * @param string|array|callable $handler
   * @return RouteItem
   */
  public function any(string|array $paths, string|array|callable $handler): RouteItem
  {
    return $this->add($paths, $handler, '*');
  }

  /**
   * 分组路由
   *
   * @access public
   * @param string|array $prefix 前缀
   * @param Closure $closure 闭包
   * @param string $id 非注解路由，系统无法生成唯一且不变的id，需手动指定id
   * @return RouteGroup
   */
  public function group(string|array $prefix, Closure $closure, string $id): RouteGroup
  {
    $route = new RouteGroup($prefix, $closure, $this->currentGroup, id: $id);
    // 判断是否存在路由分组，如果存在则添加到当前分组
    if ($this->currentGroup === null) {
      $this->routes[] = $route;
    } else {
      $this->currentGroup->addItem($route);
    }
    return $route;
  }

  /**
   * miss路由（在未匹配到路由的时候输出）
   * @access public
   * @param Closure $handler
   * @param string|string[] $method
   * @return void
   */
  public function miss(
    Closure      $handler,
    string|array $method = '*'
  ): void
  {
    if (!is_array($method)) $method = [$method];
    foreach ($method as $item) {
      $this->missRoutes[$item] = new RouteMiss($handler);
    }
  }

  /**
   * 服务路由定义
   *
   * @param string $serverName 不区分大小写
   * @param Closure $closure
   * @return void
   */
  public function server(string $serverName, Closure $closure): void
  {
    // 如果是是当前正在运行的服务，则加载路由
    if (strtolower($serverName) === strtolower(Server::getName())) {
      $closure();
    }
  }
}