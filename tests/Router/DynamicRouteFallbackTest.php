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
use ReflectionProperty;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Middleware;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;
use Viswoole\Router\RouteTable;
use Viswoole\Router\Router;

/**
 * 动态路由段数回退匹配回归测试
 *
 * 修复前缺陷：动态路由按规则段数分组（segment_N），dispatch 按请求 URL
 * 段数取桶——变量正则即使放开跨段（含 /，如 file/{path:path} 匹配
 * a/b.js）也永远匹配不到段数不同的 URL（文档 16.3 记载的机制限制）。
 *
 * 修复语义：同段数桶保持优先遍历（常规命中零额外开销），未命中时
 * 其余动态分组作为回退候选追加，跨段正则可被扫到。
 */
class DynamicRouteFallbackTest extends TestCase
{
  /**
   * 构建经生产注册链路的路由器实例
   *
   * @param callable $handler 路由处理函数
   * @param string $routePath 路由相对路径
   * @param array|null $patterns 变量正则约束
   * @return Router
   */
  private function buildRouter(
    callable $handler,
    string   $routePath = 'file/{path}',
    ?array   $patterns = ['path' => '[\\w.\\-/]+'],
  ): Router
  {
    $app = App::factory();
    $group = new Group('/bench', fn() => null, null, id: 'bench');
    $route = new Route($routePath, $handler, $group, id: 'api');
    if ($patterns !== null) $route->setPatterns($patterns);
    $group->addItem($route);
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $this->setProperty(Collector::class, 'routes', $router, ['bench' => $group]);
    $config = $app->make(Config::class);
    $this->setProperty(Router::class, 'config', $router, $config);
    $middleware = (new ReflectionClass(Middleware::class))->newInstanceWithoutConstructor();
    $this->setProperty(Router::class, 'middleware', $router, $middleware);
    $routeTable = new RouteTable($config);
    $this->setProperty(Router::class, 'routeTable', $router, $routeTable);
    foreach ($route->getPaths() as $path) {
      $routeTable->insert($path, 'bench.api', $route);
    }
    return $router;
  }

  /**
   * 设置目标对象的属性值（兼容 protected 与未初始化的 readonly）
   */
  private function setProperty(string $class, string $name, object $instance, mixed $value): void
  {
    $property = new ReflectionProperty($class, $name);
    $property->setValue($instance, $value);
  }

  /**
   * 跨段变量正则应命中段数更多的 URL（修复前按段数取桶失配 404）
   */
  public function testCrossSegmentRegexMatchesUrlWithMoreSegments(): void
  {
    // file/{path} 声明 3 段（bench/file/{path}），请求 4 段（a/b.js 跨段）
    $router = $this->buildRouter(fn(string $path) => $path);

    $this->assertSame('a/b.js', $router->dispatch('/bench/file/a/b.js', 'GET', 'localhost'));
  }

  /**
   * 同段数常规命中保持优先（回归锁定：默认约束下行为不变）
   */
  public function testSameSegmentMatchUnaffected(): void
  {
    $router = $this->buildRouter(fn(string $path) => $path);

    $this->assertSame('a.txt', $router->dispatch('/bench/file/a.txt', 'GET', 'localhost'));
  }

  /**
   * 跨段正则也放不下的路径仍应 404（回退不放宽匹配语义）
   */
  public function testUnmatchedStillThrows(): void
  {
    $router = $this->buildRouter(fn(string $path) => $path);

    $this->expectException(RouteNotFoundException::class);
    // file 段后无内容，{path} 非空约束失配
    $router->dispatch('/bench/file/', 'GET', 'localhost');
  }
}
