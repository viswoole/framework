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
use ReflectionMethod;
use Viswoole\Core\Contract\MiddlewareInterface;
use Viswoole\Core\Middleware;

/**
 * 中间件实例缓存回归测试（P2-15）
 *
 * 修复前缺陷：类中间件在管道执行时每请求 invokeClass 实例化——
 * 反射解析 + 构造注入的开销发生在每个请求（业务压测 p50 258ms 的
 * 嫌疑构成之一）。
 *
 * 修复语义：无请求态构造依赖（未注入 Request/Response）的类中间件
 * 实例进程级缓存复用（键 = class:md5(params)）；含请求态依赖的中间件
 * 每请求新建；构造参数含不可序列化值（如闭包）时放弃缓存。
 *
 * ⚠️ 实例缓存是进程级静态状态：需要断言「首次构造」的用例各自使用
 * 独立中间件类，避免跨用例污染。
 */
class MiddlewareInstanceCacheTest extends TestCase
{
  /**
   * 无请求态构造依赖的类中间件应进程级缓存复用（两次请求命中同一实例）
   *
   * ⚠️ 用 spl_object_id 断言实例身份而非静态构造计数——PHPUnit 默认逆序执行
   * 同文件用例，静态计数会被同类前序用例污染
   */
  public function testStatelessClassMiddlewareInstanceCached(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [CacheableCountingMiddleware::class]);
    $firstId = CacheableCountingMiddleware::$lastInstanceId;
    self::assertNotSame(0, $firstId);

    $middleware->process(function () {
    }, [CacheableCountingMiddleware::class]);
    self::assertSame($firstId, CacheableCountingMiddleware::$lastInstanceId, '两次请求应命中同一缓存实例');
  }

  /**
   * 不同构造参数应产生不同实例（缓存键含 params）
   */
  public function testDifferentParamsProduceDifferentInstances(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [[ParamTagMiddleware::class, ['tag' => 'a']]]);
    $firstId = ParamTagMiddleware::$lastInstanceId;
    self::assertSame('a', ParamTagMiddleware::$lastTag);

    $middleware->process(function () use (&$seen) {
    }, [[ParamTagMiddleware::class, ['tag' => 'b']]]);
    self::assertSame('b', ParamTagMiddleware::$lastTag);
    self::assertNotSame($firstId, ParamTagMiddleware::$lastInstanceId, '不同参数应各自构造');
  }

  /**
   * 相同参数重复注册应命中同一缓存实例
   */
  public function testSameParamsHitSameCacheKey(): void
  {
    $middleware = new Middleware();

    $middleware->process(function () {
    }, [[ParamTagMiddleware::class, ['tag' => 'same']]]);
    $firstId = ParamTagMiddleware::$lastInstanceId;

    $middleware->process(function () {
    }, [[ParamTagMiddleware::class, ['tag' => 'same']]]);
    self::assertSame($firstId, ParamTagMiddleware::$lastInstanceId, '相同参数应命中缓存复用实例');
  }

  /**
   * isRequestScoped 判定：构造注入 Request/Response 的类判为请求态
   * （含联合类型参数），无参类判为非请求态
   */
  public function testRequestScopedDetection(): void
  {
    $method = new ReflectionMethod(Middleware::class, 'isRequestScoped');

    self::assertTrue($method->invoke(null, RequestInjectedMiddleware::class));
    self::assertTrue($method->invoke(null, UnionRequestInjectedMiddleware::class));
    self::assertFalse($method->invoke(null, CacheableCountingMiddleware::class));
    self::assertFalse($method->invoke(null, NoConstructorMiddleware::class));
  }

  /**
   * 闭包中间件行为不受影响（回归锁定：执行顺序与参数注入）
   */
  public function testClosureMiddlewareStillWorks(): void
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
      CacheableCountingMiddleware::class,
    ]);

    self::assertSame('done', $result);
    self::assertSame(['closure-before', 'handler', 'closure-after'], $order);
  }
}

/**
 * 测试用中间件：无构造参数，process 时回写实例身份
 */
class CacheableCountingMiddleware implements MiddlewareInterface
{
  public static int $lastInstanceId = 0;

  public function process(Closure $handler): mixed
  {
    self::$lastInstanceId = spl_object_id($this);
    return $handler();
  }
}

/**
 * 测试用中间件：构造参数 tag，process 时回写实例身份与最近标签
 */
class ParamTagMiddleware implements MiddlewareInterface
{
  public static string $lastTag = '';
  public static int $lastInstanceId = 0;

  public function __construct(private readonly string $tag)
  {
  }

  public function process(Closure $handler): mixed
  {
    self::$lastTag = $this->tag;
    self::$lastInstanceId = spl_object_id($this);
    return $handler();
  }
}

/**
 * 测试用中间件：构造注入请求态类型（RequestInterface）
 */
class RequestInjectedMiddleware implements MiddlewareInterface
{
  public function __construct(\Viswoole\HttpServer\RequestInterface $request)
  {
  }

  public function process(Closure $handler): mixed
  {
    return $handler();
  }
}

/**
 * 测试用中间件：联合类型参数中含请求态类型（应同样判为请求态）
 */
class UnionRequestInjectedMiddleware implements MiddlewareInterface
{
  public function __construct(int|string|\Viswoole\HttpServer\RequestInterface $source)
  {
  }

  public function process(Closure $handler): mixed
  {
    return $handler();
  }
}

/**
 * 测试用中间件：无构造函数
 */
class NoConstructorMiddleware implements MiddlewareInterface
{
  public function process(Closure $handler): mixed
  {
    return $handler();
  }
}
