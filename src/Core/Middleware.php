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

declare(strict_types=1);

namespace Viswoole\Core;

use Closure;
use InvalidArgumentException;
use Viswoole\Core\Contract\MiddlewareInterface;

/**
 * 中间件管理器
 *
 * 提供全局中间件和服务级中间件的注册与管道式执行能力，
 * 支持闭包中间件和实现 MiddlewareInterface 的类中间件。
 */
class Middleware
{
  /**
   * @var array 全局中间件列表，应用于所有服务器
   */
  protected array $middlewares = [];
  /**
   * @var array 按服务器名称分组的中间件列表
   */
  protected array $serverMiddlewares = [];

  /**
   * 注册中间件，指定 server 名称时注册到对应服务，否则注册为全局中间件
   *
   * Example usage:
   * ```
   * // 闭包中间件，必须调用 $handler 才能继续执行，支持依赖注入
   * \Viswoole\Core\Facade\Middleware::register(function (RequestInterface $request, ResponseInterface $response, Closure $handler) {
   *   return $handler();
   * }, 'http');
   * // 类中间件，必须实现 MiddlewareInterface，构造参数由容器自动解析
   * \Viswoole\Core\Facade\Middleware::register(UserAuthMiddleware::class, 'http');
   * // 类中间件 + 显式构造参数：未传入的依赖仍由容器自动注入（按名称或位置合并），
   * // 若类定义了 public static factory() 则参数注入 factory（与容器 make 语义一致）
   * \Viswoole\Core\Facade\Middleware::register([RateLimitMiddleware::class, ['limit' => 10]], 'http');
   * ```
   *
   * @param callable|string|array $handler 中间件处理器，支持闭包、中间件类名、
   *   [类名, 构造参数数组]、[classOrInstance, method]、可调用函数名称，
   *   详见 {@see self::checkMiddleware()}
   * @param string|null $server 服务器名称，为 null 时注册为全局中间件
   */
  public function register(
    callable|string|array $handler,
    ?string               $server = null
  ): void {
    if ($server) {
      $this->serverMiddlewares[$server][] = self::checkMiddleware($handler);
    } else {
      $this->middlewares[] = self::checkMiddleware($handler);
    }
  }

  /**
   * 校验中间件处理器是否合法，类中间件必须实现 MiddlewareInterface 接口
   *
   * 本方法幂等：重复传入已规范化的结构会原样返回，可供 register()、process()
   * 及路由层对同一处理器多次校验而不会误报。支持的格式：
   * - 中间件类名字符串：规范化为 ['class' => 类名, 'params' => []]
   * - [类名, 构造参数数组]：规范化为 ['class' => 类名, 'params' => 参数数组]，
   *   与可调用数组 [class, 'method'] 通过第二元素类型（数组/字符串）无歧义区分
   * - 闭包、[classOrInstance, method]、全局函数名等可调用类型：原样返回
   *
   * @param callable|string|array $handler 中间件处理器
   * @return callable|array 合法化的中间件处理器，类中间件统一为 ['class' => ..., 'params' => ...] 结构
   * @throws InvalidArgumentException 中间件类未实现接口或处理器不可调用时抛出
   */
  public static function checkMiddleware(callable|string|array $handler): callable|array
  {
    // 已是规范化的类中间件结构（幂等处理）：register() 与 process() 会对同一处理器重复校验
    if (is_array($handler) && count($handler) === 2
      && isset($handler['class'], $handler['params'])
      && is_string($handler['class']) && is_array($handler['params'])) {
      self::assertMiddlewareClass($handler['class']);
      return $handler;
    }
    if (is_string($handler) && class_exists($handler)) {
      self::assertMiddlewareClass($handler);
      return ['class' => $handler, 'params' => []];
    }
    // [类名, 构造参数数组] 格式：第二元素为数组（可调用数组的第二元素必为字符串方法名，不会冲突）
    if (is_array($handler) && count($handler) === 2
      && isset($handler[0]) && is_string($handler[0]) && class_exists($handler[0])
      && isset($handler[1]) && is_array($handler[1])) {
      self::assertMiddlewareClass($handler[0]);
      return ['class' => $handler[0], 'params' => $handler[1]];
    }
    // [对象实例, 构造参数数组]：实例已创建无法再传构造参数，抛出明确错误，
    // 避免落入 isCallable 后在容器层触发 class_exists() 的 TypeError
    if (is_array($handler) && count($handler) === 2
      && isset($handler[0], $handler[1]) && is_object($handler[0]) && is_array($handler[1])) {
      throw new InvalidArgumentException(
        '$handler参数格式错误：[对象实例, 构造参数数组] 不受支持，构造参数仅支持 [类名, 参数数组] 格式，对象实例请使用 [实例, 方法名] 可调用格式'
      );
    }
    if (!App::isCallable($handler)) {
      throw new InvalidArgumentException(
        '$handler参数不是可调用类型，只支持闭包、中间件类名、[类名, 构造参数数组]、[classOrInstance, method]、可调用函数名称'
      );
    }
    return $handler;
  }

  /**
   * 断言类中间件实现了 MiddlewareInterface 接口
   *
   * @param string $class 中间件类名
   * @throws InvalidArgumentException 类未实现 MiddlewareInterface 时抛出
   */
  private static function assertMiddlewareClass(string $class): void
  {
    $implements = class_implements($class);
    if ($implements === false || !isset($implements[MiddlewareInterface::class])) {
      throw new InvalidArgumentException(
        "\$handler参数值 $class 不是一个有效的中间件类,必须实现" . MiddlewareInterface::class . '接口'
      );
    }
  }

  /**
   * 以管道模式执行中间件链，全局→服务级→额外传入的中间件，最终调用核心处理器
   *
   * @param callable $handler 核心业务处理器
   * @param array $middlewares 额外的中间件列表，支持 {@see self::checkMiddleware()} 所述全部格式
   * @return mixed 核心处理器的返回值
   */
  public function process(callable $handler, array $middlewares = []): mixed
  {
    $middlewares = array_map(function ($middleware) {
      return self::checkMiddleware($middleware);
    }, $middlewares);
    $serverMiddlewares = defined('SERVER_NAME')
      ? ($this->serverMiddlewares[SERVER_NAME] ?? []) : [];
    $middlewares = array_merge($this->middlewares, $serverMiddlewares, $middlewares);
    // 创建中间件管道
    $pipeline = array_reduce(
      array_reverse($middlewares),
      function (Closure $carry, callable|array $middleware) {
        return function () use ($middleware, $carry) {
          // ['class' => ..., 'params' => ...] 结构的类中间件：
          // 参数仅用于构造（语义A），经容器与自动注入合并后实例化，每请求新建实例
          if (is_array($middleware) && isset($middleware['class'], $middleware['params'])) {
            $instance = App::factory()->invokeClass($middleware['class'], $middleware['params']);
            return invoke([$instance, 'process'], ['handler' => $carry]);
          }
          return invoke($middleware, ['handler' => $carry]);
        };
      },
      $handler
    );
    return invoke($pipeline);
  }
}
