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

class EventHandle
{
  /**
   * 处理http请求
   *
   * @access public
   * @param Request $request swoole HTTP请求对象
   * @param Response $response swoole HTTP响应对象
   * @return void
   * @throws ServerNotFoundException
   */
  public static function onRequest(
    Request  $request,
    Response $response
  ): void
  {
    $app = App::factory();
    try {
      /**
       * @var $psr7Request RequestInterface
       */
      $psr7Request = $app->make(RequestInterface::class, [$request]);
      /**
       * @var $psr7Response ResponseInterface
       */
      $psr7Response = $app->make(ResponseInterface::class, [$response]);
      // 交由路由分发
      $result = $app->router->collector()->dispatch(
        $psr7Request->getPath(),
        $psr7Request->params(),
        $psr7Request->getMethod(),
        $psr7Request->getUri()->getHost()
      );
      if ($result instanceof ResponseInterface) {
        $result->send();
      } elseif (is_array($result) || is_object($result)) {
        // 返回的不是response对象 则对返回的参数进行json格式化。
        $psr7Response->json($result)->send();
      } else {
        $psr7Response->send((string)$result);
      }
    } catch (Throwable $e) {
      $exceptionHandle = $app->server->getConfig()['exception_handle'] ?? Handle::class;
      $app->invokeMethod([$exceptionHandle, 'render'], [$e]);
    }
  }
}
