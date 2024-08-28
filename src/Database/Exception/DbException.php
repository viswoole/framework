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

namespace Viswoole\Database\Exception;

use RuntimeException;
use Throwable;

/**
 * 数据库异常
 */
class DbException extends RuntimeException
{
  protected ?string $sql;

  /**
   * @param string $message 错误信息
   * @param int $code 错误码
   * @param string|null $sql 出错的sql语句
   * @param Throwable|null $previous
   */
  public function __construct(
    string     $message,
    int        $code = 0,
    ?string    $sql = null,
    ?Throwable $previous = null
  )
  {
    $this->sql = $sql;
    parent::__construct($message, $code, $previous);
  }

  /**
   * 获取出错的sql语句
   *
   * @return string|null
   */
  public function getSql(): ?string
  {
    return $this->sql;
  }
}