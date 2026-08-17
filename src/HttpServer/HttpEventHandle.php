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

namespace Viswoole\HttpServer;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;
use Viswoole\Core\App;
use Viswoole\Core\Exception\Handle;
use Viswoole\Core\Exception\ServerNotFoundException;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Contract\ResponseInterface;

/**
 * HTTP 请求事件处理器
 *
 * 作为 Swoole HTTP Server 的 onRequest 回调入口，
 * 负责将 Swoole 原始请求/响应对象封装为 PSR-7 风格对象，
 * 交由路由分发并处理响应输出。
 */
class HttpEventHandle
{
  /**
   * Swoole onRequest 回调入口
   *
   * 构造请求/响应对象 → 路由分发 → 处理响应；异常交由异常处理器渲染。
   *
   * @param Request $request Swoole 原始请求对象
   * @param Response $response Swoole 原始响应对象
   * @throws ServerNotFoundException 服务未找到时抛出
   */
  public static function onRequest(
    Request  $request,
    Response $response
  ): void
  {
    $app = App::factory();
    try {
      /**
       * @var RequestInterface $psr7Request
       */
      $psr7Request = $app->make(RequestInterface::class, [$request]);
      /**
       * @var ResponseInterface $psr7Response
       */
      $psr7Response = $app->make(ResponseInterface::class, [$response]);
      // 交由路由分发
      $result = $app->router->dispatch(
        $psr7Request->getPath(),
        $psr7Request->getMethod(),
        $psr7Request->getUri()->getHost(),
        callback: function (array $params) use ($psr7Request) {
          // 将动态路由匹配到的参数添加到请求参数中
          $psr7Request->addParams($params, 'get');
        }
      );
      self::handleResponse($result, $psr7Response);
    } catch (Throwable $e) {
      $exceptionHandle = $app->server->getConfig()['exception_handle'] ?? Handle::class;
      $app->invokeMethod([$exceptionHandle, 'render'], [$e]);
    }
  }

  /**
   * 根据路由返回值类型选择响应方式
   *
   * ResponseInterface 实例直接 send；数组/对象以 JSON 响应；其它转为字符串响应。
   *
   * @param mixed $result 路由分发返回值
   * @param ResponseInterface $psr7Response 响应对象
   */
  public static function handleResponse(mixed $result, ResponseInterface $psr7Response): void
  {
    if ($psr7Response->isWritable()) {
      if ($result instanceof ResponseInterface) {
        $result->send();
      } elseif (is_array($result) || is_object($result)) {
        // 返回的不是 Response 对象，则对返回的参数进行 JSON 格式化。
        $psr7Response->json($result)->send();
      } else {
        $psr7Response->send((string)$result);
      }
    }
  }
}
