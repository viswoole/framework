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

namespace Viswoole\Tests\Core;

use Closure;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\Contract\MiddlewareInterface;
use Viswoole\Core\Middleware;

/**
 * 中间件实例缓存回归测试（P2-15，IS_STATELESS 显式声明制）
 *
 * 设计决策（用户拍板 2026-09-23）：框架不做启发式静态分析判定中间件是否
 * 可缓存——构造注入只是请求数据的入口之一，Facade 门面、全局函数同样可以
 * 拿到请求态。实例复用为 **opt-in 显式声明**：类声明 `const IS_STATELESS = true`
 * 才启用进程级缓存复用（键 = class:md5(params)），默认每请求实例化，
 * 存量中间件零行为变化。
 *
 * ⚠️ 实例缓存是进程级静态状态：各用例使用独立中间件类，避免跨用例污染。
 * ⚠️ 断言用强引用持有的实例对比——无引用的实例被 GC 后 spl_object_id 会
 * 被新实例复用，不能直接比较裸 id。
 */
class MiddlewareInstanceCacheTest extends TestCase
{
  /**
   * 未声明 IS_STATELESS 的类默认每请求新建实例（存量中间件零行为变化）
   */
  public function testStatefulNotCachedByDefault(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [StatefulMiddleware::class]);
    $first = end(StatefulMiddleware::$instances);

    $middleware->process(function () {
    }, [StatefulMiddleware::class]);
    $second = end(StatefulMiddleware::$instances);

    self::assertNotSame($first, $second, '默认应每请求实例化（未声明不缓存）');
  }

  /**
   * 声明 IS_STATELESS = true 的类应进程级缓存复用（两次请求命中同一实例）
   */
  public function testStatelessDeclaredCached(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [StatelessDeclaredMiddleware::class]);
    $first = end(StatelessDeclaredMiddleware::$instances);

    $middleware->process(function () {
    }, [StatelessDeclaredMiddleware::class]);
    $second = end(StatelessDeclaredMiddleware::$instances);

    self::assertSame($first, $second, '声明无状态应复用实例');
  }

  /**
   * 显式声明 IS_STATELESS = false 的类不缓存（与未声明同语义）
   */
  public function testDeclaredFalseNotCached(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [StatelessFalseMiddleware::class]);
    $first = end(StatelessFalseMiddleware::$instances);

    $middleware->process(function () {
    }, [StatelessFalseMiddleware::class]);
    $second = end(StatelessFalseMiddleware::$instances);

    self::assertNotSame($first, $second);
  }

  /**
   * 声明类：不同构造参数产生不同实例（缓存键含 params）
   */
  public function testDifferentParamsProduceDifferentInstances(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [[StatelessTaggedMiddleware::class, ['tag' => 'a']]]);
    $first = end(StatelessTaggedMiddleware::$instances);
    self::assertSame('a', StatelessTaggedMiddleware::$lastTag);

    $middleware->process(function () {
    }, [[StatelessTaggedMiddleware::class, ['tag' => 'b']]]);
    $second = end(StatelessTaggedMiddleware::$instances);
    self::assertSame('b', StatelessTaggedMiddleware::$lastTag);
    self::assertNotSame($first, $second, '不同参数应各自构造');
  }

  /**
   * 声明类：相同构造参数重复注册命中同一缓存实例
   */
  public function testSameParamsHitSameCacheKey(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [[StatelessTaggedMiddleware::class, ['tag' => 'same']]]);
    $first = end(StatelessTaggedMiddleware::$instances);

    $middleware->process(function () {
    }, [[StatelessTaggedMiddleware::class, ['tag' => 'same']]]);
    $second = end(StatelessTaggedMiddleware::$instances);

    self::assertSame($first, $second, '相同参数应命中缓存复用实例');
  }

  /**
   * 闭包中间件与未声明类混合管道行为不受影响（回归锁定：执行顺序）
   */
  public function testClosureAndStatefulMixedPipelineStillWorks(): void
  {
    $middleware = new Middleware();
    $order = [];

    $result = $middleware->process(function () use (&$order) {
      $order[] = 'handler';
      return 'done';
    }, [
      function (Closure $handler) use (&$order) {
        $order[] = 'closure-before';
        $result = $handler();
        $order[] = 'closure-after';
        return $result;
      },
      StatefulMiddleware::class,
    ]);

    self::assertSame('done', $result);
    self::assertSame(['closure-before', 'handler', 'closure-after'], $order);
  }
}

/**
 * 测试用中间件：未声明 IS_STATELESS（默认每请求实例化）。
 * $instances 持有强引用防止对象 GC 后 spl_object_id 复用干扰断言
 */
class StatefulMiddleware implements MiddlewareInterface
{
  public static array $instances = [];

  public function process(Closure $handler): mixed
  {
    self::$instances[] = $this;
    return $handler();
  }
}

/**
 * 测试用中间件：显式声明无状态（启用实例缓存复用）
 */
class StatelessDeclaredMiddleware implements MiddlewareInterface
{
  public const IS_STATELESS = true;

  public static array $instances = [];

  public function process(Closure $handler): mixed
  {
    self::$instances[] = $this;
    return $handler();
  }
}

/**
 * 测试用中间件：显式声明 false（与未声明同语义）
 */
class StatelessFalseMiddleware implements MiddlewareInterface
{
  public const IS_STATELESS = false;

  public static array $instances = [];

  public function process(Closure $handler): mixed
  {
    self::$instances[] = $this;
    return $handler();
  }
}

/**
 * 测试用中间件：声明无状态且带构造参数（验证缓存键含 params）。
 * $instances 持有强引用（见 StatefulMiddleware 注释）
 */
class StatelessTaggedMiddleware implements MiddlewareInterface
{
  public const IS_STATELESS = true;

  public static string $lastTag = '';
  public static array $instances = [];

  public function __construct(private readonly string $tag)
  {
  }

  public function process(Closure $handler): mixed
  {
    self::$lastTag = $this->tag;
    self::$instances[] = $this;
    return $handler();
  }
}
