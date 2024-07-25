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

use Override;
use Viswoole\Core\Service\Provider;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Contract\ResponseInterface;

/**
 * Http服务提供者
 */
class HttpService extends Provider
{
  /**
   * @inheritDoc
   */
  #[Override] public function boot(): void
  {
  }

  /**
   * @inheritDoc
   */
  #[Override] public function register(): void
  {
    if (class_exists('\App\Request')) {
      $requestClass = \App\Request::class;
      $this->app->bind(\App\Request::class, \App\Request::class);
    } else {
      $requestClass = Request::class;
    }
    if (class_exists('\App\Response')) {
      $responseClass = \App\Response::class;
      $this->app->bind(\App\Response::class, \App\Response::class);
    } else {
      $responseClass = Response::class;
    }
    $this->app->bind('request', $requestClass);
    $this->app->bind('response', $responseClass);
    $this->app->bind(RequestInterface::class, $requestClass);
    $this->app->bind(ResponseInterface::class, $responseClass);
    $this->app->bind(Request::class, $requestClass);
    $this->app->bind(Response::class, $responseClass);
  }
}
