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

namespace Viswoole\Core;

use Closure;
use InvalidArgumentException;
use Viswoole\Core\Contract\MiddlewareInterface;

/**
 * 中间件管理器
 */
class Middleware
{
  /**
   * @var array 全局中间件
   */
  protected array $middlewares = [];
  /**
   * @var array 服务中间件
   */
  protected array $serverMiddlewares = [];

  /**
   * 注册一个全局中间件
   *
   * Example usage:
   *
   * ```
   * // 注册一个闭包中间件，必须调用$handler才能往下执行，支持依赖注入
   * \Viswoole\Core\Facade\Middleware::register(function (RequestInterface $request, ResponseInterface $response, Closure $handler) {
   *   // 中间件逻辑
   *   return $handler();
   * }, 'http');
   * // 注册一个实现了MiddlewareInterface接口的类
   * \Viswoole\Core\Facade\Middleware::register(UserAuthMiddleware::class, 'http');
   * ```
   * @param callable|string|array $handler 中间件
   * @param string|null $server 服务器名称，默认为null，表示应用于所有服务器
   * @return void
   */
  public function register(
    callable|string|array $handler,
    string                $server = null
  ): void
  {
    if ($server) {
      $this->serverMiddlewares[$server][] = self::checkMiddleware($handler);
    } else {
      $this->middlewares[] = self::checkMiddleware($handler);
    }
  }

  /**
   * 验证中间件是否有效
   *
   * @param callable|string|array $handler
   * @return callable|array
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
   * 运行中间件
   *
   * @param callable $handler 最终的处理者,支持任意可调用的类型回调
   * @param array<string|callable> $middlewares 额外的中间件
   * @return mixed
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
