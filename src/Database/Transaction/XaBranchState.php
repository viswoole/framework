<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChonglin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Database\Transaction;

/**
 * XA 分支状态机
 *
 * 状态流转（提交路径）：Active --XA END--> Ended --XA PREPARE--> Prepared --XA COMMIT--> Done
 * 状态流转（放弃路径）：Active/Ended/Prepared --XA ROLLBACK--> Done
 *
 * Prepared 及之后的分支事务由数据库服务端持有（连接已解除关联，可安全回池），
 * Active/Ended 分支仍依附于当前连接，归还连接前必须显式终结。
 */
enum XaBranchState: string
{
  /** XA START 已执行，业务语句可继续写入 */
  case Active = 'active';
  /** XA END 已执行，分支不再接受业务语句 */
  case Ended = 'ended';
  /** XA PREPARE 已执行，事务由服务端持有等待终结指令 */
  case Prepared = 'prepared';
  /** XA COMMIT / XA ROLLBACK 已执行，分支终结 */
  case Done = 'done';
}
