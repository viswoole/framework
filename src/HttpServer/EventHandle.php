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
 * HTTP服务事件处理
 */
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
       * @var RequestInterface $psr7Request
       */
      $psr7Request = $app->make(RequestInterface::class, [$request]);
      /**
       * @var ResponseInterface $psr7Response
       */
      $psr7Response = $app->make(ResponseInterface::class, [$response]);
      $params = $psr7Request->params();
      // 交由路由分发
      $result = $app->router->collector()->dispatch(
        $psr7Request->getPath(),
        $params,
        $psr7Request->getMethod(),
        $psr7Request->getUri()->getHost()
      );
      // 将动态匹配到的参数添加到请求参数中
      $psr7Request->addParams($params);
      self::handleResponse($result, $psr7Response);
    } catch (Throwable $e) {
      $exceptionHandle = $app->server->getConfig()['exception_handle'] ?? Handle::class;
      $app->invokeMethod([$exceptionHandle, 'render'], [$e]);
    }
  }

  /**
   * 处理响应
   *
   * @param mixed $result
   * @param ResponseInterface $psr7Response
   * @return void
   */
  public static function handleResponse(mixed $result, ResponseInterface $psr7Response): void
  {
    if ($psr7Response->isWritable()) {
      if ($result instanceof ResponseInterface) {
        $result->send();
      } elseif (is_array($result) || is_object($result)) {
        // 返回的不是response对象 则对返回的参数进行json格式化。
        $psr7Response->json($result)->send();
      } else {
        $psr7Response->send((string)$result);
      }
    }
  }
}
