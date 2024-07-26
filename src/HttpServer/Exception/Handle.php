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

namespace Viswoole\HttpServer\Exception;

use Throwable;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Contract\ResponseInterface;
use Viswoole\HttpServer\Status;
use Viswoole\Log\LogManager;
use Viswoole\Router\Exception\RouteNotFoundException;

/**
 * 异常处理类
 */
class Handle extends \Viswoole\Core\Exception\Handle
{
  /**
   * @var array 忽略的异常
   */
  protected array $ignoreReport = [
    ValidateException::class,
    HttpException::class,
    RouteNotFoundException::class
  ];

  public function __construct(
    LogManager                           $log,
    protected readonly ResponseInterface $response,
    protected readonly RequestInterface  $request
  )
  {
    parent::__construct($log);
  }

  /**
   * 处理异常
   *
   * @param Throwable $e
   * @return void
   */
  public function render(Throwable $e): void
  {
    parent::render($e);
    $statusCode = 500;
    $message = $e->getMessage();
    $trace = null;
    /**
     * @var ResponseInterface $response
     */
    if ($e instanceof HttpException) {
      $statusCode = $e->getHttpCode();
      $this->response->setHeaders($e->getHeaders());
    } elseif ($e instanceof RouteNotFoundException) {
      $statusCode = Status::NOT_FOUND;
    } elseif ($e instanceof ValidateException) {
      $statusCode = Status::BAD_REQUEST;
    } elseif (isDebug()) {
      $message = $e->getMessage();
      $trace = $e->getTrace();
    } else {
      $message = 'Internal Server Error';
    }
    $this->response->status($statusCode)->json([
      'code' => $e->getCode(),
      'message' => $message,
      'data' => $trace
    ])->send();
  }
}
