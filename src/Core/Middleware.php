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
   * // 类中间件，必须实现 MiddlewareInterface
   * \Viswoole\Core\Facade\Middleware::register(UserAuthMiddleware::class, 'http');
   * ```
   *
   * @param callable|string|array $handler 中间件处理器
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
   * 校验中间件处理器是否合法，类名中间件必须实现 MiddlewareInterface 接口
   *
   * @param callable|string|array $handler 中间件处理器
   * @return callable|array 合法化的中间件处理器，类名转为 [ClassName, 'process']
   * @throws InvalidArgumentException 中间件类未实现接口或处理器不可调用时抛出
   */
  public static function checkMiddleware(callable|string|array $handler): callable|array
  {
    if (is_string($handler) && class_exists($handler)) {
      $implements = class_implements($handler);
      if ($implements === false || !in_array(MiddlewareInterface::class, $implements)) {
        throw new InvalidArgumentException(
          "\$handler参数值 $handler 不是一个有效的中间件类,必须实现" . MiddlewareInterface::class . '接口'
        );
      } else {
        return [$handler, 'process'];
      }
    }
    if (!App::isCallable($handler)) {
      throw new InvalidArgumentException(
        '$handler参数不是可调用类型，只支持闭包、中间件类名、[classOrInstance,method]、可调用函数名称'
      );
    }
    return $handler;
  }

  /**
   * 以管道模式执行中间件链，全局→服务级→额外传入的中间件，最终调用核心处理器
   *
   * @param callable $handler 核心业务处理器
   * @param array<string|callable> $middlewares 额外的中间件列表
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
      function (Closure $carry, $middleware) {
        return function () use ($middleware, $carry) {
          return invoke($middleware, ['handler' => $carry]);
        };
      },
      $handler
    );
    return invoke($pipeline);
  }
}
