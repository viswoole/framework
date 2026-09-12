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

namespace Viswoole\Tests\Router;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Middleware;
use Viswoole\Router\RouteTable;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;
use Viswoole\Router\Router;

/**
 * 动态路由参数隐式注入回归测试
 *
 * 覆盖场景：dispatch() 未显式传参（HTTP 请求场景）时，动态路由匹配参数
 * 应按参数名隐式注入处理器（/bench/user/1024 → $id = 1024）。
 *
 * 通过反射构建路由表，绕过构造函数的完整加载流程（路由文件加载、控制器
 * 目录扫描），聚焦验证参数合并逻辑本身。
 */
class DispatchRouteParamTest extends TestCase
{
  /**
   * 构建注入了分组动态路由表的路由器实例
   *
   * 复用生产 insertRoute() 构建动态路由映射，避免测试手写正则与实现漂移。
   *
   * @param callable $handler 路由处理函数
   * @return Router
   */
  private function buildRouter(callable $handler): Router
  {
    $app = App::factory();
    // 组装分组 bench 与子路由 bench.api（路径合并结果为 /bench/user/{id}）
    $group = new Group('/bench', fn() => null, null, id: 'bench');
    $route = new Route('/user/{id}', $handler, $group, id: 'api');
    $group->addItem($route);
    // 绕过构造函数创建实例，避免触发路由文件加载与控制器目录扫描
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $this->setProperty(Collector::class, 'routes', $router, ['bench' => $group]);
    $config = $app->make(Config::class);
    $this->setProperty(Router::class, 'config', $router, $config);
    // 中间件管理器同样绕过构造，避免加载全局中间件（如跨域中间件依赖 Swoole 上下文）
    $middleware = (new ReflectionClass(Middleware::class))->newInstanceWithoutConstructor();
    $this->setProperty(Router::class, 'middleware', $router, $middleware);
    // 路由表已拆分到 RouteTable：注入空表并复用生产 insert() 注册动态路由
    $routeTable = new RouteTable($config);
    $this->setProperty(Router::class, 'routeTable', $router, $routeTable);
    // 通过生产方法注册动态路由：/bench/user/{id} → bench.api
    $routeTable->insert('/bench/user/{id}', 'bench.api', $route);
    return $router;
  }

  /**
   * 设置目标对象的属性值（兼容 protected 与未初始化的 readonly）
   *
   * @param string $class 属性声明类
   * @param string $name 属性名
   * @param object $instance 目标实例
   * @param mixed $value 属性值
   * @return void
   */
  private function setProperty(string $class, string $name, object $instance, mixed $value): void
  {
    $property = new ReflectionProperty($class, $name);
    $property->setValue($instance, $value);
  }

  /**
   * 未显式传参时，动态路由参数应按名隐式注入处理器参数
   *
   * @return void
   */
  public function testDynamicRouteParamImplicitInjection(): void
  {
    $router = $this->buildRouter(fn(string $id) => "user:$id");

    $result = $router->dispatch('/bench/user/1024', 'GET', 'localhost');

    $this->assertSame('user:1024', $result);
  }

  /**
   * 回调应收到动态路由匹配参数（如 HTTP 场景并入 Request GET）
   *
   * @return void
   */
  public function testCallbackReceivesRouteParams(): void
  {
    $router = $this->buildRouter(fn(string $id) => $id);
    $captured = null;

    $router->dispatch(
      '/bench/user/1024', 'GET', 'localhost',
      callback: function (array $params) use (&$captured): void {
        $captured = $params;
      }
    );

    $this->assertSame(['id' => '1024'], $captured);
  }

  /**
   * 显式传参时，路由匹配参数优先于手动传入的同名参数，其余参数保留
   *
   * @return void
   */
  public function testExplicitParamsMergedWithPatternPriority(): void
  {
    $router = $this->buildRouter(fn(string $id, string $extra) => "$id:$extra");

    $result = $router->dispatch(
      '/bench/user/1024', 'GET', 'localhost',
      params: ['id' => '2048', 'extra' => 'x']
    );

    $this->assertSame('1024:x', $result);
  }
}
