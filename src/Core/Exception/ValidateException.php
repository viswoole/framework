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
 * 验证异常
 */
class ValidateException extends InvalidArgumentException
{
  /**
   * 验证错误信息
   * @var string|array
   */
  protected string|array $error;

  public function __construct(string|array $error, int $code = 0, Throwable|null $previous = null)
  {
    $this->error = $error;
    $message = is_array($error) ? implode(PHP_EOL, $error) : $error;
    parent::__construct($message, $code, $previous);
  }

  /**
   * 获取验证错误信息
   * @access public
   * @return array|string
   */
  public function getError(): array|string
  {
    return $this->error;
  }
}
