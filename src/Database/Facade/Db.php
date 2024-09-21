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

use Closure;
use Override;
use Viswoole\Core\Facade;
use Viswoole\Database\BaseQuery;
use Viswoole\Database\Channel;
use Viswoole\Database\DbManager;
use Viswoole\Database\Query\RunInfo;
use Viswoole\Database\Raw;

/**
 * 数据库通道管理器
 *
 * @method static Channel channel(?string $name = null) 获取数据库通道
 * @method static bool hasChannel(string $channel_name) 判断通道是否存在
 * @method static void start() 开启事务
 * @method static void startTransaction(Closure $query = null) 开启事务, 传入闭包则自动管理事务
 * @method static void commit() 提交事务
 * @method static void rollBack() 回滚所有事务
 * @method static Raw raw(string $sql, array $bindings = []) 原生sql
 * @method static BaseQuery table(string $table, string $pk = 'id') 选择要查询的表
 * @method static array query(string $sql, array $bindings = []) 原生查询 select
 * @method static int|string execute(string $sql, array $bindings = []) 原生写入，包括 insert、update、delete
 * @method static mixed pop(string $type) 获取可用的连接$type可选值为`read`|`write`
 * @method static void put(mixed $connect) 归还一个可用的连接，如果连接已被损坏，请归还null
 * @method static void setDebug(bool $debug) 设置debug模式
 * @method static void setDebugInfoSaveManner(int $manner) 设置调试信息保存方式
 * @method static void saveDebugInfo(RunInfo $debugInfo) 保存调试信息
 * @method static bool debug() 是否开启debug
 * @method static int debugInfoSaveManner() 调试信息保存方式
 * @method static void addChannel(string $name, Channel|string|array $channel) 添加通道
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Database\\Facade\\Db
 */
class Db extends Facade
{

  /**
   * debug信息直接输出到控制台
   */
  const int DEBUG_SAVE_CONSOLE = 1;
  /**
   * debug信息保存到日志文件
   */
  const int DEBUG_SAVE_LOGGER = 2;

  /**
   * 获取当前Facade对应类名
   *
   * @access protected
   * @return string
   */
  #[Override] protected static function getMappingClass(): string
  {
    return DbManager::class;
  }
}
