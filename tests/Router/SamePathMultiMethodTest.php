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
use Viswoole\Router\Annotation\Controller;
use Viswoole\Router\Annotation\RouteMapping;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;
use Viswoole\Router\Router;

/**
 * 同一路径不同请求方法的路由注册与分发回归测试
 *
 * 覆盖场景：同一路径（如 GET /api/user/profile 与 PUT /api/user/profile）
 * 允许注册多条不同请求方法的路由，分发时按方法维度选择，互不覆盖；
 * 同时覆盖 OPTIONS 预检回退、通配优先级、动态路由方法隔离与 ApiDoc 兼容性。
 *
 * 通过反射构建路由器实例，绕过构造函数的完整加载流程（路由文件加载、控制器
 * 目录扫描），复用生产 parseRoute()/insertRoute() 完成注册，聚焦验证方法
 * 维度路由表本身。
 */
class SamePathMultiMethodTest extends TestCase
{
  /**
   * 构建绕过构造函数的路由器实例
   *
   * @return Router
   */
  private function makeRouter(): Router
  {
    $app = App::factory();
    // 绕过构造函数创建实例，避免触发路由文件加载与控制器目录扫描
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $this->setProperty(Collector::class, 'routes', $router, []);
    $this->setProperty(Collector::class, 'currentGroup', $router, null);
    $this->setProperty(Collector::class, 'missRoutes', $router, []);
    $this->setProperty(Router::class, 'config', $router, $app->make(Config::class));
    // 中间件管理器同样绕过构造，避免加载全局中间件（如跨域中间件依赖 Swoole 上下文）
    $middleware = (new ReflectionClass(Middleware::class))->newInstanceWithoutConstructor();
    $this->setProperty(Router::class, 'middleware', $router, $middleware);
    $this->setProperty(Router::class, 'staticRoute', $router, []);
    $this->setProperty(Router::class, 'dynamicRoute', $router, []);
    return $router;
  }

  /**
   * 调用生产 parseRoute() 完成路由注册（排序、分组装配、路由表写入）
   *
   * @param Router $router 路由器实例
   * @return void
   */
  private function buildRouteTable(Router $router): void
  {
    (new ReflectionMethod(Router::class, 'parseRoute'))->invoke($router);
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
   * 配置路由：同一路径不同请求方法应各自分发到对应处理器
   *
   * @return void
   */
  public function testConfigRoutesSamePathDifferentMethodsDispatchSeparately(): void
  {
    $router = $this->makeRouter();
    $router->addRoute('/api/user/profile', fn() => 'get-profile', 'GET');
    $router->addRoute('/api/user/profile', fn() => 'put-profile', 'PUT');
    $this->buildRouteTable($router);

    $this->assertSame('get-profile', $router->dispatch('/api/user/profile', 'GET', 'localhost'));
    $this->assertSame('put-profile', $router->dispatch('/api/user/profile', 'PUT', 'localhost'));
  }

  /**
   * 分组路由（注解场景）：同一路径不同请求方法应各自分发到对应处理器
   *
   * 模拟控制器注解流程：Controller 分组 + 两个 RouteMapping 方法
   * （GET 与 PUT）路径合并后指向同一路径。
   *
   * @return void
   */
  public function testGroupRoutesSamePathDifferentMethodsDispatchSeparately(): void
  {
    $router = $this->makeRouter();
    $group = new Group('/api/user', fn() => null, null, id: 'user');
    $group->addItem(new Route('profile', fn() => 'get-profile', $group, id: 'get', methods: ['GET']));
    $group->addItem(new Route('profile', fn() => 'put-profile', $group, id: 'put', methods: ['PUT']));
    $this->setProperty(Collector::class, 'routes', $router, ['user' => $group]);
    $this->buildRouteTable($router);

    $this->assertSame('get-profile', $router->dispatch('/api/user/profile', 'GET', 'localhost'));
    $this->assertSame('put-profile', $router->dispatch('/api/user/profile', 'PUT', 'localhost'));
  }

  /**
   * 路径命中但请求方法均不匹配时应抛出方法不允许异常
   *
   * @return void
   */
  public function testMethodNotAllowedWhenPathMatchesOtherMethodsOnly(): void
  {
    $router = $this->makeRouter();
    $router->addRoute('/api/user/profile', fn() => 'get-profile', 'GET');
    $router->addRoute('/api/user/profile', fn() => 'put-profile', 'PUT');
    $this->buildRouteTable($router);

    $this->expectException(RouteNotFoundException::class);
    $this->expectExceptionMessage("request method 'DELETE' is not allowed");

    $router->dispatch('/api/user/profile', 'DELETE', 'localhost');
  }

  /**
   * OPTIONS 预检请求应回退到该路径下任意路由（放行进入中间件管道）
   *
   * @return void
   */
  public function testOptionsPreflightFallsBackToAnyRoute(): void
  {
    $router = $this->makeRouter();
    $router->addRoute('/only-get', fn() => 'get-only', 'GET');
    $this->buildRouteTable($router);

    $this->assertSame('get-only', $router->dispatch('/only-get', 'OPTIONS', 'localhost'));
  }

  /**
   * 动态路由：同一正则不同请求方法应各自分发且动态参数正常注入
   *
   * @return void
   */
  public function testDynamicRoutesSamePatternDifferentMethods(): void
  {
    $router = $this->makeRouter();
    $router->addRoute('/bench/user/{id}', fn(string $id) => "get:$id", 'GET');
    $router->addRoute('/bench/user/{id}', fn(string $id) => "put:$id", 'PUT');
    $this->buildRouteTable($router);

    $this->assertSame('get:1024', $router->dispatch('/bench/user/1024', 'GET', 'localhost'));
    $this->assertSame('put:1024', $router->dispatch('/bench/user/1024', 'PUT', 'localhost'));
  }

  /**
   * 动态路由：路径命中但方法不匹配时应继续扫描后续正则
   *
   * 同段数下存在两条不同约束的动态路由（数字约束 GET / 字母约束 POST），
   * POST 数字路径应跳过 GET 规则继续匹配，最终报告方法不允许而非 404。
   *
   * @return void
   */
  public function testDynamicRouteContinuesScanWhenMethodMismatch(): void
  {
    $router = $this->makeRouter();
    $numeric = $router->addRoute('/bench/item/{id}', fn() => 'get-numeric', 'GET');
    $numeric->setPatterns(['id' => '\d+']);
    $word = $router->addRoute('/bench/item/{name}', fn() => 'post-word', 'POST');
    $word->setPatterns(['name' => '[a-z]+']);
    $this->buildRouteTable($router);

    $this->assertSame('get-numeric', $router->dispatch('/bench/item/123', 'GET', 'localhost'));
    $this->assertSame('post-word', $router->dispatch('/bench/item/abc', 'POST', 'localhost'));

    $this->expectException(RouteNotFoundException::class);
    $this->expectExceptionMessage("request method 'POST' is not allowed");
    $router->dispatch('/bench/item/123', 'POST', 'localhost');
  }

  /**
   * 精确方法匹配应优先于 '*' 通配路由
   *
   * @return void
   */
  public function testExactMethodTakesPrecedenceOverWildcard(): void
  {
    $router = $this->makeRouter();
    $router->any('/mix', fn() => 'any');
    $router->addRoute('/mix', fn() => 'get', 'GET');
    $this->buildRouteTable($router);

    $this->assertSame('get', $router->dispatch('/mix', 'GET', 'localhost'));
    $this->assertSame('any', $router->dispatch('/mix', 'POST', 'localhost'));
  }

  /**
   * 同路径同方法重复定义应触发告警，且后定义者覆盖前者
   *
   * 注解路由 id 由 类::方法 生成（同路径同方法的两个方法 id 不同），
   * 是唯一能到达"方法映射表覆盖"这一分支的重复定义场景。
   *
   * @return void
   */
  public function testDuplicateSamePathSameMethodTriggersWarningAndOverrides(): void
  {
    $router = $this->makeRouter();
    $group = new Group('/api/user', fn() => null, null, id: 'user');
    $group->addItem(new Route('profile', fn() => 'first', $group, id: 'get1', methods: ['GET']));
    $group->addItem(new Route('profile', fn() => 'second', $group, id: 'get2', methods: ['GET']));
    $this->setProperty(Collector::class, 'routes', $router, ['user' => $group]);

    // 捕获 E_USER_WARNING，避免 phpunit failOnWarning 中断用例
    $warnings = [];
    set_error_handler(
      static function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;
        return true;
      }
    );
    try {
      $this->buildRouteTable($router);
    } finally {
      restore_error_handler();
    }

    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('/api/user/profile', $warnings[0]);
    $this->assertStringContainsString('GET', $warnings[0]);
    // 后定义者覆盖前者
    $this->assertSame('second', $router->dispatch('/api/user/profile', 'GET', 'localhost'));
  }

  /**
   * 同路径不同方法的路由 id 应互不相同（配置路由无显式 id 场景）
   *
   * @return void
   */
  public function testSamePathDifferentMethodsGenerateDistinctIds(): void
  {
    $get = new Route('/api/user/profile', fn() => null, methods: ['GET']);
    $put = new Route('/api/user/profile', fn() => null, methods: ['PUT']);

    $this->assertNotSame($get->getId(), $put->getId());
  }

  /**
   * setMethod 应兼容数组形式传参（注解 method: ['GET','POST'] 场景）
   *
   * @return void
   */
  public function testSetMethodAcceptsArrayForm(): void
  {
    $route = new Route('/array-method', fn() => null);

    $route->setMethod(['GET', 'post']);

    $this->assertSame(['GET', 'POST'], $route->getMethod());
  }

  /**
   * RouteMapping 注解数组形式的 method 应能正常创建路由（回归：此前触发 TypeError）
   *
   * @return void
   */
  public function testRouteMappingArrayMethodCreatesRoute(): void
  {
    $arrayForm = new RouteMapping(paths: '/x', method: ['GET', 'POST']);
    $stringForm = new RouteMapping(paths: '/x', method: 'put');

    $this->assertSame(['GET', 'POST'], $arrayForm->create(fn() => null)->getMethod());
    $this->assertSame(['PUT'], $stringForm->create(fn() => null)->getMethod());
  }

  /**
   * ApiDoc 文档列表应包含同路径不同方法的两个独立入口，且详情可按引用链路解析
   *
   * @return void
   */
  public function testApiDocListContainsBothMethodEntries(): void
  {
    $router = $this->makeRouter();
    $group = new Group('/api/user', fn() => null, null, id: 'user');
    $group->addItem(new Route('profile', fn() => 'get-profile', $group, id: 'get', methods: ['GET']));
    $group->addItem(new Route('profile', fn() => 'put-profile', $group, id: 'put', methods: ['PUT']));
    $this->setProperty(Collector::class, 'routes', $router, ['user' => $group]);
    $this->buildRouteTable($router);
    // 开启文档开关并标记初始化完成，绕过构造函数未设置的私有状态
    $this->setProperty(Router::class, 'enableApiDoc', $router, true);
    $this->setProperty(Router::class, 'init', $router, true);

    $apiList = $router->getApiList();

    $this->assertSame(2, $apiList['count']);
    $children = $apiList['routes'][0]['children'];
    $this->assertSame(['GET'], $children[0]['methods']);
    $this->assertSame(['PUT'], $children[1]['methods']);
    $this->assertNotSame($children[0]['id'], $children[1]['id']);
    $this->assertSame('/api/user/profile', $children[0]['paths'][0]);
    $this->assertSame('/api/user/profile', $children[1]['paths'][0]);
    // 两条引用链路均可解析详情（params/returned 结构完整）
    foreach ($children as $child) {
      $detail = $router->getApiDetail($child['citeLink']);
      $this->assertArrayHasKey('params', $detail);
      $this->assertArrayHasKey('returned', $detail);
    }
  }

  /**
   * 注解路由端到端流程：parseMethod 解析同路径不同方法的注解方法并正确分发
   *
   * 复现用户控制器场景：#[Controller(prefix: '/api/user')] 分组下两个
   * #[RouteMapping(paths: 'profile')] 方法分别以 GET/PUT 注册。
   *
   * @return void
   */
  public function testAnnotationParseMethodFlowSamePathDifferentMethods(): void
  {
    $router = $this->makeRouter();
    // 模拟 parseController() 的分组构建（title 对应真实流程从类文档注释提取）
    $controller = new Controller(prefix: '/api/user', id: 'user', title: 'C端用户');
    $group = $controller->create([]);
    // 复用生产 parseMethod() 解析夹具控制器的注解方法
    $refClass = new ReflectionClass(SamePathAnnotationFixture::class);
    (new ReflectionMethod(Router::class, 'parseMethod'))
      ->invoke($router, $refClass->getMethods(), false, $group);
    // 复用 loadAnnotationRoute() 的记录流程
    $router->recordRouteItem($group);
    $this->buildRouteTable($router);

    $this->assertSame('get-profile', $router->dispatch('/api/user/profile', 'GET', 'localhost'));
    $this->assertSame('put-profile', $router->dispatch('/api/user/profile', 'PUT', 'localhost'));
  }
}

/**
 * 注解路由测试夹具：同一路径以不同请求方法定义两个接口（模拟用户控制器）
 */
#[Controller(prefix: '/api/user', id: 'fixture-user', title: 'C端用户')]
class SamePathAnnotationFixture
{
  /**
   * 个人资料
   */
  #[RouteMapping(paths: 'profile', method: 'GET', title: '获取个人资料')]
  public static function profile(): string
  {
    return 'get-profile';
  }

  /**
   * 更新个人资料
   */
  #[RouteMapping(paths: 'profile', method: 'PUT', title: '更新个人资料')]
  public static function updateProfile(): string
  {
    return 'put-profile';
  }
}
