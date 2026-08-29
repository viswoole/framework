<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core;

use Closure;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\Contract\MiddlewareInterface;
use Viswoole\Core\Middleware;
use stdClass;

/**
 * 中间件注册与管道执行测试
 *
 * 重点覆盖 [类名, 构造参数数组] 格式（语义A：参数仅用于构造）、
 * checkMiddleware 的幂等性，以及既有格式（类名/闭包/可调用数组）的回归。
 */
class MiddlewareTest extends TestCase
{
  /**
   * 每个用例前重置夹具的静态记录，避免用例间状态串扰
   *
   * @return void
   */
  protected function setUp(): void
  {
    MiddlewareParamFixture::$resolved = [];
  }

  /**
   * 测试类名字符串被规范化为 ['class' => 类名, 'params' => []] 结构
   *
   * @return void
   */
  public function testCheckMiddlewareNormalizesClassName(): void
  {
    $result = Middleware::checkMiddleware(MiddlewareParamFixture::class);
    static::assertSame(
      ['class' => MiddlewareParamFixture::class, 'params' => []],
      $result
    );
  }

  /**
   * 测试 [类名, 构造参数数组] 格式被规范化为参数化结构
   *
   * @return void
   */
  public function testCheckMiddlewareNormalizesClassWithParams(): void
  {
    $result = Middleware::checkMiddleware([MiddlewareParamFixture::class, ['limit' => 10]]);
    static::assertSame(
      ['class' => MiddlewareParamFixture::class, 'params' => ['limit' => 10]],
      $result
    );
  }

  /**
   * 测试 checkMiddleware 幂等性：规范化结构重复传入原样返回
   *
   * @return void
   */
  public function testCheckMiddlewareIsIdempotent(): void
  {
    $first = Middleware::checkMiddleware([MiddlewareParamFixture::class, ['limit' => 10]]);
    // 二次校验（模拟 register()/process() 对同一处理器的重复校验）不得误报
    $second = Middleware::checkMiddleware($first);
    static::assertSame($first, $second);
  }

  /**
   * 测试可调用数组 [classOrInstance, method] 格式不受新格式影响，原样返回
   *
   * @return void
   */
  public function testCheckMiddlewareKeepsCallableArray(): void
  {
    $instance = new MiddlewareParamFixture(limit: 1);
    $handler = [$instance, 'process'];
    static::assertSame($handler, Middleware::checkMiddleware($handler));
  }

  /**
   * 测试未实现 MiddlewareInterface 的类名抛出异常
   *
   * @return void
   */
  public function testCheckMiddlewareRejectsClassWithoutInterface(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    Middleware::checkMiddleware(stdClass::class);
  }

  /**
   * 测试 [未实现接口的类名, 构造参数数组] 同样抛出接口缺失异常
   *
   * @return void
   */
  public function testCheckMiddlewareRejectsParamFormatWithoutInterface(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    Middleware::checkMiddleware([stdClass::class, ['any' => 1]]);
  }

  /**
   * 测试不可调用类型抛出异常
   *
   * @return void
   */
  public function testCheckMiddlewareRejectsNonCallable(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    Middleware::checkMiddleware('not_exists_class_or_function');
  }

  /**
   * 测试管道按构造参数实例化类中间件（语义A），参数经容器按名称注入
   *
   * @return void
   */
  public function testProcessInjectsConstructorParams(): void
  {
    $middleware = new Middleware();
    $result = $middleware->process(
      function () {
        return 'core';
      },
      [[MiddlewareParamFixture::class, ['limit' => 10]]]
    );
    static::assertSame('core', $result);
    // 中间件应在核心处理器执行前收到构造参数
    static::assertSame([10], MiddlewareParamFixture::$resolved);
  }

  /**
   * 测试类名字符串格式（回归）：构造参数走容器默认解析
   *
   * @return void
   */
  public function testProcessWithPlainClassName(): void
  {
    $middleware = new Middleware();
    $result = $middleware->process(
      function () {
        return 'core';
      },
      [MiddlewareParamFixture::class]
    );
    static::assertSame('core', $result);
    // 未传构造参数时使用默认值 0
    static::assertSame([0], MiddlewareParamFixture::$resolved);
  }

  /**
   * 测试闭包格式（回归）
   *
   * @return void
   */
  public function testProcessWithClosure(): void
  {
    $middleware = new Middleware();
    $result = $middleware->process(
      function () {
        return 'core';
      },
      [function (Closure $handler) {
        return 'closure:' . $handler();
      }]
    );
    static::assertSame('closure:core', $result);
  }

  /**
   * 测试 register() 后的管道执行：注册阶段与执行阶段对同一处理器重复校验仍正常（幂等回归）
   *
   * @return void
   */
  public function testRegisterThenProcessWithParamsFormat(): void
  {
    $middleware = new Middleware();
    // 注册阶段规范化为结构，管道执行时再次 checkMiddleware 必须能识别该结构
    $middleware->register([MiddlewareParamFixture::class, ['limit' => 99]]);
    $result = $middleware->process(function () {
      return 'core';
    });
    static::assertSame('core', $result);
    static::assertSame([99], MiddlewareParamFixture::$resolved);
  }

  /**
   * 测试位置参数数组按位置注入构造函数
   *
   * @return void
   */
  public function testProcessInjectsPositionalParams(): void
  {
    $middleware = new Middleware();
    $result = $middleware->process(
      function () {
        return 'core';
      },
      [[MiddlewareParamFixture::class, [66]]]
    );
    static::assertSame('core', $result);
    static::assertSame([66], MiddlewareParamFixture::$resolved);
  }

  /**
   * 测试 params 中多余键被静默忽略（与容器 make 的既有语义一致）
   *
   * @return void
   */
  public function testProcessIgnoresUnknownParamKeys(): void
  {
    $middleware = new Middleware();
    $result = $middleware->process(
      function () {
        return 'core';
      },
      [[MiddlewareParamFixture::class, ['limit' => 5, 'unknown_key' => 'x']]]
    );
    static::assertSame('core', $result);
    static::assertSame([5], MiddlewareParamFixture::$resolved);
  }

  /**
   * 测试无构造函数的类中间件接收 params 时被静默忽略且正常执行
   *
   * 边界说明：与容器 invokeClass 既有语义一致（无构造函数则无参数可匹配），
   * 不抛错、不中断，params 不会传递到 process()
   *
   * @return void
   */
  public function testProcessIgnoresParamsWhenNoConstructor(): void
  {
    $middleware = new Middleware();
    $result = $middleware->process(
      function () {
        return 'core';
      },
      [[MiddlewareNoConstructorFixture::class, ['any' => 1]]]
    );
    static::assertSame('core', $result);
  }

  /**
   * 测试 [对象实例, 构造参数数组] 抛出明确的参数格式异常而非容器层 TypeError
   *
   * @return void
   */
  public function testCheckMiddlewareRejectsObjectWithParams(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('对象实例');
    Middleware::checkMiddleware([new MiddlewareParamFixture(), ['limit' => 1]]);
  }

  /**
   * 测试洋葱模型顺序（夹具补充）
   *
   * @return void
   */
  public function testProcessMaintainsOnionOrder(): void
  {
    $middleware = new Middleware();
    $order = [];
    $result = $middleware->process(
      function () use (&$order) {
        $order[] = 'core';
        return 'core';
      },
      [
        [MiddlewareParamFixture::class, ['limit' => 1]],
        function (Closure $handler) use (&$order) {
          $order[] = 'closure:before';
          $result = $handler();
          $order[] = 'closure:after';
          return $result;
        },
      ]
    );
    static::assertSame('core', $result);
    static::assertSame(
      ['closure:before', 'core', 'closure:after'],
      $order
    );
  }
}

/**
 * 测试夹具：带标量构造参数的中间件，实例化时记录参数供断言
 */
class MiddlewareParamFixture implements MiddlewareInterface
{
  /**
   * 已实例化中间件收到的构造参数记录（静态以便测试断言）
   *
   * @var array<int,mixed>
   */
  public static array $resolved = [];

  /**
   * 构造函数
   *
   * @param int $limit 示例参数，容器按名称注入，未传时取默认值
   */
  public function __construct(public int $limit = 0)
  {
    self::$resolved[] = $limit;
  }

  /**
   * 执行中间件逻辑，直接放行到下一层
   *
   * @param Closure $handler 下一个中间件的处理闭包
   * @return mixed 核心处理器的返回值
   */
  public function process(Closure $handler): mixed
  {
    return $handler();
  }
}

/**
 * 测试夹具：无构造函数的中间件
 */
class MiddlewareNoConstructorFixture implements MiddlewareInterface
{
  public function process(Closure $handler): mixed
  {
    return $handler();
  }
}
