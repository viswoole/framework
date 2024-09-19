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

use RuntimeException;
use Throwable;

/**
 * HTTP异常
 */
class HttpException extends RuntimeException
{
  /**
   * @var int HTTP响应状态码
   */
  private int $httpCode;
  /**
   * @var array HTTP响应头
   */
  private array $headers;

  /**
   * @param string $message 异常提示信息
   * @param int $code 异常代码
   * @param int $httpCode 状态码
   * @param Throwable|null $previous 上一个异常
   * @param array $headers 响应头
   */
  public function __construct(
    string    $message = 'error',
    int       $code = -1,
    int       $httpCode = 200,
    Throwable $previous = null,
    array     $headers = [],
  )
  {
    $this->httpCode = $httpCode;
    $this->headers = $headers;
    parent::__construct($message, $code, $previous);
  }

  /**
   * 获取响应状态码
   * @access public
   * @return int
   */
  public function getHttpCode(): int
  {
    return $this->httpCode;
  }

  /**
   * 获取响应头
   * @access public
   * @return array
   */
  public function getHeaders(): array
  {
    return $this->headers;
  }
}
