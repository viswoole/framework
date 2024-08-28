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

namespace Viswoole\Database\Channel\PDO;
/**
 * PDO支持的数据库驱动
 */
enum DriverType: string
{
  case MYSQL = 'mysql';
  case POSTGRESQL = 'pgsql';
  case ORACLE = 'oci';
  case SQLite = 'sqlite';
  case SQLServer = 'sqlsrv';
}
