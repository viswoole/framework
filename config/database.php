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

// +----------------------------------------------------------------------
// | 数据库配置
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\Database\Channel\PDO\PDOChannel;

return [
  // 默认通道
  'default' => env('DATABASE_DEFAULT', 'default'),
  // 通道列表
  'channel' => [
    // 驱动类需继承Viswoole\Database\Driver\ConnectionDriver
    'default' => new PDOChannel(
      host    : env('DATABASE_HOST', '127.0.0.1'),
      port    : env('DATABASE_PORT', 3306),
      database: env('DATABASE_NAME', ''),
      username: env('DATABASE_USER', 'root'),
      password: env('DATABASE_PASSWORD', ''),
    )
  ]
];
