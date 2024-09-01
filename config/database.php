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
use Viswoole\Database\Facade\Db;

return [
  // 默认通道
  'default' => env('DATABASE_DEFAULT', 'default'),
  // 是否开启调试模式
  'debug' => env('app_debug', true),
  // 调试信息保存方式，1保存到控制台，2保存到日志文件，3保存到控制台和日志文件
  'info_save_manner' => Db::DEBUG_SAVE_CONSOLE | Db::DEBUG_SAVE_LOGGER,
  // 通道列表
  'channels' => [
    // 驱动类需继承Viswoole\Database\Channel
    'default' => new PDOChannel(
      host    : env('DATABASE_HOST', '127.0.0.1'),
      port    : (int)env('DATABASE_PORT', 3306),
      database: env('DATABASE_NAME', ''),
      username: env('DATABASE_USER', 'root'),
      password: env('DATABASE_PASSWORD', ''),
    )
  ]
];
