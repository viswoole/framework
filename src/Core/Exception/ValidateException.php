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

namespace Viswoole\Core\Exception;

use InvalidArgumentException;
use Throwable;

/**
 * 数据验证失败时抛出，携带验证错误信息供上层获取
 */
class ValidateException extends InvalidArgumentException
{
  /**
   * @var string|array 验证错误信息，支持单条字符串或多条错误数组
   */
  protected string|array $error;

  /**
   * @param string|array $error 验证错误信息，多条时传入数组
   * @param int $code 异常错误码
   * @param Throwable|null $previous 前一个异常
   */
  public function __construct(string|array $error, int $code = 0, Throwable|null $previous = null)
  {
    $this->error = $error;
    $message = is_array($error) ? implode(PHP_EOL, $error) : $error;
    parent::__construct($message, $code, $previous);
  }

  /**
   * 获取原始验证错误信息
   *
   * @return array|string 单条错误返回字符串，多条错误返回数组
   */
  public function getError(): array|string
  {
    return $this->error;
  }
}
