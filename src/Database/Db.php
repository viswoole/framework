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

namespace Viswoole\Database;

use Closure;
use Viswoole\Database\Collector\Query;
use Viswoole\Database\Collector\Raw;
use Viswoole\Database\Driver\Contract\DriverInterface;
use Viswoole\Database\Facade\DbChannelManager;

class Db
{
  /**
   * 连接数据库表
   *
   * @param string $name 表名称
   * @param string $pk 主键
   * @param string|DriverInterface|null $channel 数据库通道/驱动
   * @return Query
   */
  public static function table(
    string                 $name,
    string                 $pk = 'id',
    string|DriverInterface $channel = null
  ): Query
  {
    if (!$channel instanceof DriverInterface) $channel = DbChannelManager::channel($channel);
    return new Query($channel, $name, $pk);
  }

  /**
   * 用该方法处理字符串，编译SQL语句时会将其当做原生sql语句，不进行额外处理
   *
   * @param string $sql
   * @return Raw
   */
  public static function raw(string $sql): Raw
  {
    return new Raw($sql);
  }

  /**
   * 自动事务
   * @param Closure $closure 执行查询的闭包
   * @return void
   */
  public static function action(Closure $closure): void
  {
  }

  /**
   * 提交事务
   * @access public
   * @return void
   */
  public static function commit(): void
  {
  }

  /**
   * 回滚事务
   * @access public
   * @return void
   */
  public static function rollback(): void
  {
  }

  /**
   * 开启事务
   *
   * @access public
   * @return void
   */
  public static function startTrans(): void
  {
  }
}
