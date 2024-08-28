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

use Throwable;

/**
 * 查询数据为空时抛出异常
 */
class DataNotFoundException extends DbException
{
  /**
   * @param string $message
   * @param int $code
   * @param string|null $sql
   * @param Throwable|null $previous
   */
  public function __construct(
    string     $message,
    int        $code = 0,
    ?string    $sql = null,
    ?Throwable $previous = null
  )
  {
    parent::__construct($message, $code, $sql, $previous);
  }
}
