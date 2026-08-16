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
   * 处理跨域请求：为所有响应添加 CORS 头，OPTIONS 预检请求短路直接返回
   *
   * CORS 头必须对预检与实际请求都存在（浏览器对两者均校验），故无条件设置；
   * 预检请求无业务语义，短路返回避免落入路由处理器。
   *
   * @param Closure $handler 下一个中间件的处理闭包
   * @return mixed OPTIONS 请求返回响应对象，其他请求返回后续处理结果
   */
  #[Override] public function process(Closure $handler): mixed
  {
    // Access-Control-Max-Age 缓存预检结果，减少重复预检（单位秒，1 天）
    $this->response->setHeaders([
      'Access-Control-Allow-Origin' => '*',
      'Access-Control-Allow-Headers' => '*',
      'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
      'Access-Control-Max-Age' => '86400',
    ]);
    if ($this->request->getMethod() === 'OPTIONS') {
      return $this->response;
    }
    return $handler();
  }
}
