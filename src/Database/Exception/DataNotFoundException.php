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
 * 查询结果为空异常
 *
 * 当期望至少一条记录但查询返回空结果集时抛出。
 */
class DataNotFoundException extends DbException
{
  /**
   * @param string $message 错误描述
   * @param int $code 错误码
   * @param string|null $sql 出错的 SQL 语句
   * @param Throwable|null $previous 上级异常
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
