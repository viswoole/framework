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

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Middleware;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;
use Viswoole\Router\Router;

/**
 * 动态路由参数提取健壮性回归测试
 *
 * 历史缺陷（均为 array_combine 崩溃或参数错位/丢失）：
 * - 同一路径重复变量名或约束正则含匿名捕获组时，dispatch 按
 *   patterns 键序对齐捕获组导致 array_combine 键值数量不等而崩溃
 * - 伪静态后缀在路由选择前全局剥离，参数值含点号（/user/john.doe）
 *   被静默截断为 john
 * - 请求路径未做 URL 解码，编码参数值（/user/hello%20world）直接 404
 * - setPatterns 校验用 / 作分隔符，含 [/] 语法的合法约束（[^/]+）被误判无效
 *
 * 修复语义：convertRegex 使用命名捕获组，dispatch 按组名提取参数；
 * 重复/非法变量名在注册期抛出明确异常；候选路径"完整优先、剥离后缀回退"；
 * 请求路径逐段 URL 解码。
 */
class DynamicRouteParamEdgeTest extends TestCase
{
  /**
   * 构建经生产注册链路的路由器实例
   *
   * @param callable $handler 路由处理函数
   * @param string $routePath 路由相对路径
   * @param array|null $patterns 变量正则约束
   * @param string|null $groupPath 组路径
   * @return Router
   */
  private function buildRouter(
    callable $handler,
    string   $routePath,
    ?array   $patterns = null,
    ?string  $groupPath = '/bench',
  ): Router
  {
    $app = App::factory();
    $group = new Group($groupPath, fn() => null, null, id: 'bench');
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
   * 同一路径内重复变量名应在注册期抛出明确异常（旧实现为分发期 array_combine 崩溃）
   *
   * @return void
   */
  public function testDuplicateVariableNameThrowsAtRegistration(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage("重复定义");
    $this->buildRouter(fn(string $id) => $id, 'user/{id}/sub/{id}');
  }

  /**
   * 组路径与子路径变量同名同样应在注册期拒绝（合并后同一结果路径）
   *
   * @return void
   */
  public function testDuplicateVariableNameAcrossGroupAndChildThrows(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->buildRouter(fn(string $id) => $id, 'book/{id}', null, '/user/{id}');
  }

  /**
   * 非法变量名（无法作为 PCRE 命名捕获组）应在注册期抛出明确异常
   *
   * @return void
   */
  public function testInvalidVariableNameThrowsAtRegistration(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('非法');
    $this->buildRouter(fn(string $id) => $id, 'user/{1abc}');
  }

  /**
   * 约束正则含匿名捕获组不应崩溃，且捕获组不混入参数表（命名组隔离）
   *
   * @return void
   */
  public function testCapturingGroupInPatternDoesNotBreak(): void
  {
    $router = $this->buildRouter(
      fn(string $id) => "id:$id",
      'user/{id}',
      ['id' => '(\d+)']
    );
    $this->assertSame('id:1024', $router->dispatch('/bench/user/1024', 'GET', 'localhost'));
  }

  /**
   * 大小写不同的变量名各自以声明时的原始大小写为键提取
   *
   * @return void
   */
  public function testCaseDifferingVariableKeyExtraction(): void
  {
    $router = $this->buildRouter(fn(string $Id) => "Id:$Id", 'user/{Id}');
    $this->assertSame('Id:ABC', $router->dispatch('/bench/user/ABC', 'GET', 'localhost'));
  }

  /**
   * 参数值含点号不应被伪静态后缀剥离截断
   *
   * @return void
   */
  public function testDotInParamValuePreserved(): void
  {
    $router = $this->buildRouter(fn(string $name) => "name:$name", 'user/{name}');
    $this->assertSame('name:john.doe', $router->dispatch('/bench/user/john.doe', 'GET', 'localhost'));
  }

  /**
   * 伪静态后缀语义保持：配置具体后缀的路由，带后缀请求按剥离后命中
   *
   * @return void
   */
  public function testPseudoStaticSuffixStillWorks(): void
  {
    $router = $this->buildRouterWithSuffix(fn(string $id) => "id:$id", 'article/{id}', ['html']);
    $this->assertSame('id:5', $router->dispatch('/bench/article/5.html', 'GET', 'localhost'));
  }

  /**
   * 构建带伪静态后缀约束的路由器实例
   *
   * @param callable $handler 路由处理函数
   * @param string $routePath 路由相对路径
   * @param array $suffix 允许的后缀列表
   * @return Router
   */
  private function buildRouterWithSuffix(
    callable $handler,
    string   $routePath,
    array    $suffix,
  ): Router
  {
    $app = App::factory();
    $group = new Group('/bench', fn() => null, null, id: 'bench');
    $route = new Route($routePath, $handler, $group, id: 'api');
    $route->setSuffix(...$suffix);
    $group->addItem($route);
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $this->setProperty(Collector::class, 'routes', $router, ['bench' => $group]);
    $this->setProperty(Router::class, 'config', $router, $app->make(Config::class));
    $middleware = (new ReflectionClass(Middleware::class))->newInstanceWithoutConstructor();
    $this->setProperty(Router::class, 'middleware', $router, $middleware);
    $this->setProperty(Router::class, 'staticRoute', $router, []);
    $this->setProperty(Router::class, 'dynamicRoute', $router, []);
    $insertRoute = new ReflectionMethod(Router::class, 'insertRoute');
    foreach ($route->getPaths() as $path) {
      $insertRoute->invoke($router, $path, 'bench.api');
    }
    return $router;
  }

  /**
   * URL 编码的参数值应解码后注入（如空格 %20）
   *
   * @return void
   */
  public function testUrlEncodedParamValueDecoded(): void
  {
    $router = $this->buildRouter(
      fn(string $name) => "name:$name",
      'user/{name}',
      ['name' => '[^/]+']
    );
    $this->assertSame('name:hello world', $router->dispatch('/bench/user/hello%20world', 'GET', 'localhost'));
  }

  /**
   * 静态段含点号的路由不应被后缀剥离破坏（完整路径候选优先命中）
   *
   * @return void
   */
  public function testStaticSegmentWithDotStillMatches(): void
  {
    $router = $this->buildRouter(fn() => 'ok', 'v1.2/user');
    $this->assertSame('ok', $router->dispatch('/bench/v1.2/user', 'GET', 'localhost'));
  }

  /**
   * setPatterns 应接受含 [/] 语法的合法约束（旧校验分隔符误判）
   *
   * @return void
   */
  public function testPatternWithSlashClassIsValid(): void
  {
    $router = $this->buildRouter(
      fn(string $name) => "name:$name",
      'user/{name}',
      ['name' => '[^/]+']
    );
    $this->assertSame('name:abc', $router->dispatch('/bench/user/abc', 'GET', 'localhost'));
  }

  /**
   * 约束正则内部自定义的命名捕获组不应泄漏进注入参数表
   *
   * @return void
   */
  public function testInternalNamedGroupInPatternFilteredOut(): void
  {
    $captured = null;
    $router = $this->buildRouter(
      fn(string $id) => "id:$id",
      'user/{id}',
      ['id' => '(?P<leak>\d+)']
    );
    $router->dispatch(
      '/bench/user/1024', 'GET', 'localhost',
      callback: function (array $params) use (&$captured): void {
        $captured = $params;
      }
    );
    $this->assertSame(['id' => '1024'], $captured);
  }

  /**
   * 请求路径中的 %2F 不应解码为真实路径分隔符重写路径结构
   *
   * @return void
   */
  public function testEncodedSlashNotDecodedAsSeparator(): void
  {
    $router = $this->buildRouter(
      fn(string $x, string $y) => "$x|$y",
      'user/{x}/{y}',
      ['x' => '[^/]+', 'y' => '[^/]+']
    );
    // a%2Fb 保持编码原样注入，路径段结构不被改写为 /bench/user/a/b/c
    $this->assertSame(
      'a%2Fb|c',
      $router->dispatch('/bench/user/a%2Fb/c', 'GET', 'localhost')
    );
  }

  /**
   * 超过 PCRE 命名组上限（32 字符）的变量名应在注册期报明确错误
   *
   * @return void
   */
  public function testOverlongVariableNameThrowsWithClearMessage(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('32 字符');
    $this->buildRouter(fn(string $v) => $v, 'user/' . '{' . str_repeat('a', 40) . '}');
  }
}
