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
 * 跨域请求中间件，自动处理 OPTIONS 预检请求并添加 CORS 响应头
 */
class AllowCrossDomain implements MiddlewareInterface
{

  /**
   * @param RequestInterface $request HTTP 请求实例
   * @param ResponseInterface $response HTTP 响应实例
   */
  public function __construct(
    protected RequestInterface  $request,
    protected ResponseInterface $response
  )
  {
  }

  /**
   * 处理跨域请求：OPTIONS 预检请求直接返回 CORS 头，其他请求放行至下一中间件
   *
   * @param Closure $handler 下一个中间件的处理闭包
   * @return mixed OPTIONS 请求返回响应对象，其他请求返回后续处理结果
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
