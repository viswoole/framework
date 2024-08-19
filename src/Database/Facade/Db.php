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

namespace Viswoole\Database\Facade;

use Viswoole\Core\Facade;
use Viswoole\Database\Channel;
use Viswoole\Database\Collector\Raw;
use Viswoole\Database\Manager\DbChannel;

/**
 * 数据库通道管理器
 *
 * @method static Channel channel(?string $name = null) 获取数据库通道
 * @method static bool hasChannel(string $channel_name) 判断通道是否存在
 * @method static void start() 开启事务
 * @method static void startTransaction() 开启事务
 * @method static void commit() 提交事务
 * @method static void rollback() 回滚所有事务
 * @method static Raw raw(string $sql) 原生sql
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Database\\Facade\\Db
 */
class Db extends Facade
{

  /**
   * 获取当前Facade对应类名
   *
   * @access protected
   * @return string
   */
  #[\Override] protected static function getMappingClass(): string
  {
    return DbChannel::class;
  }
}
