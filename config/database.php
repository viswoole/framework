<?php
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
    'default' => [
      // 通道类，必须继承Viswoole\Database\Channel
      'channel' => PDOChannel::class,
      // PDOChannel通道构造参数
      'options' => [
        'host' => env('DATABASE_HOST', '127.0.0.1'),
        'port' => (int)env('DATABASE_PORT', 3306),
        'database' => env('DATABASE_NAME', ''),
        'username' => env('DATABASE_USER', 'root'),
        'password' => env('DATABASE_PASSWORD', '123456'),
      ]
    ]
  ]
];
