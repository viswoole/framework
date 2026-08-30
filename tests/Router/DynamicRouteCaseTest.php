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
use Throwable;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Core\Middleware;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;
use Viswoole\Router\Router;

/**
 * 动态路由变量大小写回归测试
 *
 * 历史缺陷：handlePaths 对整条路径做 strtolower，导致驼峰变量名 {userId}
 * 退化为 {userid}，dispatch 提取的参数键与控制器方法参数名不一致，
 * 命名注入失败（null given），且 setPatterns 的驼峰键约束静默失效。
 *
 * 修复语义：
 * - 注册时仅小写静态段，变量段保留原始大小写（变量名对齐参数名与约束键）
 * - 大小写不敏感路由下，动态正则静态段以内联 (?i:) 分组包裹，
 *   dispatch 用原始路径匹配，参数值保留请求时的原始大小写
 */
class DynamicRouteCaseTest extends TestCase
{
  /**
   * 构建经生产注册链路的路由器实例
   *
   * 使用相对路径 'user/{id}' 触发父子路径合并（handlePaths 生产语义），
   * 再通过 insertRoute 以 getPaths() 规范化结果注册动态路由表。
   *
   * @param callable $handler 路由处理函数
   * @param string $routePath 路由相对路径（默认含驼峰变量）
   * @param array|null $patterns 变量正则约束（键为变量原始大小写）
   * @return Router
   */
  private function buildRouter(
    callable $handler,
    string   $routePath = 'user/{userId}',
    ?array   $patterns = null,
  ): Router
  {
    $app = App::factory();
    $group = new Group('/bench', fn() => null, null, id: 'bench');
    $route = new Route($routePath, $handler, $group, id: 'api');
    if ($patterns !== null) $route->setPatterns($patterns);
    $group->addItem($route);
    // 绕过构造函数创建实例，避免触发路由文件加载与控制器目录扫描
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $this->setProperty(Collector::class, 'routes', $router, ['bench' => $group]);
    $this->setProperty(Router::class, 'config', $router, $app->make(Config::class));
    $middleware = (new ReflectionClass(Middleware::class))->newInstanceWithoutConstructor();
    $this->setProperty(Router::class, 'middleware', $router, $middleware);
    $this->setProperty(Router::class, 'staticRoute', $router, []);
    $this->setProperty(Router::class, 'dynamicRoute', $router, []);
    // 与 Router::register 一致：使用规范化后的 getPaths() 注册
    $insertRoute = new ReflectionMethod(Router::class, 'insertRoute');
    foreach ($route->getPaths() as $path) {
      $insertRoute->invoke($router, $path, 'bench.api');
    }
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
   * 驼峰变量名应与控制器参数名对齐，命名注入成功
   *
   * @return void
   */
  public function testCamelCaseVariableNamedInjection(): void
  {
    $router = $this->buildRouter(fn(string $userId) => "user:$userId");

    $this->assertSame('user:1024', $router->dispatch('/bench/user/1024', 'GET', 'localhost'));
  }

  /**
   * setPatterns 以变量原始大小写为键的约束应生效
   *
   * @return void
   */
  public function testCamelCasePatternConstraintApplies(): void
  {
    $router = $this->buildRouter(
      fn(string $userId) => "user:$userId",
      'user/{userId}',
      ['userId' => '\d+']
    );
    // 约束命中：数字注入成功
    $this->assertSame('user:1024', $router->dispatch('/bench/user/1024', 'GET', 'localhost'));
    // 约束不命中：非数字不匹配该路由（约束未被默认 \w+ 静默替代）
    $this->expectException(RouteNotFoundException::class);
    $router->dispatch('/bench/user/abc', 'GET', 'localhost');
  }

  /**
   * 大小写不敏感路由下，动态参数值应保留请求时的原始大小写
   *
   * @return void
   */
  public function testRouteParamValueCasePreserved(): void
  {
    $router = $this->buildRouter(fn(string $userId) => $userId);

    $this->assertSame('JohnDoe', $router->dispatch('/bench/user/JohnDoe', 'GET', 'localhost'));
  }

  /**
   * 大小写不敏感路由下，静态段混合大小写请求仍应命中
   *
   * @return void
   */
  public function testCaseInsensitiveStaticSegmentStillMatches(): void
  {
    $router = $this->buildRouter(fn(string $userId) => $userId);

    $this->assertSame('1024', $router->dispatch('/BENCH/USER/1024', 'GET', 'localhost'));
  }

  /**
   * 大小写敏感路由下，静态段必须精确匹配，变量段大小写语义不受影响
   *
   * @return void
   */
  public function testCaseSensitiveRouteRequiresExactStaticCase(): void
  {
    $config = App::factory()->make(Config::class);
    $config->set('router.case_sensitive', true);
    try {
      $router = $this->buildRouter(fn(string $userId) => $userId);
      // 静态段大小写一致：命中
      $this->assertSame('1024', $router->dispatch('/bench/user/1024', 'GET', 'localhost'));
      // 静态段大小写不一致：不命中
      $this->expectException(RouteNotFoundException::class);
      $router->dispatch('/BENCH/user/1024', 'GET', 'localhost');
    } finally {
      // 恢复全局默认配置，避免污染同进程后续测试
      $config->set('router.case_sensitive', false);
    }
  }

  /**
   * 驼峰变量命中的异常场景兜底：参数缺失时保持原有校验报错语义
   *
   * @return void
   */
  public function testMissingRouteParamStillThrows(): void
  {
    // 可选变量缺失时回落到方法默认值，不应报错
    $router = $this->buildRouter(
      fn(string $userId, string $tag = 'none') => "$userId:$tag",
      'user/{userId}/{tag?}'
    );
    $this->assertSame('1024:none', $router->dispatch('/bench/user/1024', 'GET', 'localhost'));

    // 无默认值的必选变量：路由未命中时抛 RouteNotFoundException
    $router = $this->buildRouter(fn(string $userId, string $tag) => "$userId:$tag", 'user/{userId}/{tag}');
    try {
      $router->dispatch('/bench/user/1024', 'GET', 'localhost');
      $this->fail('expected RouteNotFoundException');
    } catch (Throwable $e) {
      $this->assertInstanceOf(RouteNotFoundException::class, $e);
    }
  }
}
