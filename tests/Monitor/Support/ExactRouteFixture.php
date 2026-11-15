<?php

declare(strict_types=1);

/* 职责：用生产路由表及分发器构造隔离接口目录，不加载应用路由文件。 */

namespace Viswoole\Tests\Monitor\Support;

use ReflectionClass;
use ReflectionProperty;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Middleware;
use Viswoole\Router\InterfaceCatalog;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Route;
use Viswoole\Router\Router;
use Viswoole\Router\RouteTable;

/** 仅隔离装配，匹配、正则编译及分发均使用生产逻辑。 */
final readonly class ExactRouteFixture
{
  public Router $router;
  public InterfaceCatalog $catalog;

  /**
   * 构建指定路由集合
   * @param list<Route> $routes 已注册定义
   */
  public function __construct(array $routes)
  {
    $config = App::factory()->make(Config::class);
    $this->router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $table = new RouteTable($config);
    $registered = [];
    foreach ($routes as $route) {
      $registered[$route->getId()] = $route;
      foreach ($route->getPaths() as $path) { $table->insert($path, $route->getId(), $route); }
    }
    $this->catalog = $table->interfaces;
    $this->catalog->complete();
    (new ReflectionProperty(Collector::class, 'routes'))->setValue($this->router, $registered);
    (new ReflectionProperty(Router::class, 'config'))->setValue($this->router, $config);
    (new ReflectionProperty(Router::class, 'routeTable'))->setValue($this->router, $table);
    (new ReflectionProperty(Router::class, 'middleware'))->setValue(
      $this->router,
      (new ReflectionClass(Middleware::class))->newInstanceWithoutConstructor()
    );
  }
}
