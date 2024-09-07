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

namespace Viswoole\Core\Middlewares;

use Closure;
use Override;
use Viswoole\Core\Contract\MiddlewareInterface;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Contract\ResponseInterface;

/**
 * 跨域中间件
 */
class AllowCrossDomain implements MiddlewareInterface
{

  /**
   * @param RequestInterface $request
   * @param ResponseInterface $response
   */
  public function __construct(
    protected RequestInterface  $request,
    protected ResponseInterface $response
  )
  {
  }

  /**
   * 中间件处理方法
   *
   * @param Closure $handler 下一个处理程序
   * @return mixed
   */
  #[Override] public function process(Closure $handler): mixed
  {
    if ($this->request->getMethod() === 'OPTIONS') {
      $this->response->setHeaders([
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => '*'
      ]);
      return $this->response;
    }
    return $handler();
  }
}
