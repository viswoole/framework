<?php
// +----------------------------------------------------------------------
// | 数据库配置
// +----------------------------------------------------------------------

declare(strict_types=1);

use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Facade\Db;

return [
  // 默认通道
  'default' => env('DATABASE_DEFAULT', 'default'),
  // 是否开启调试模式
  'debug' => env('app_debug', true),
  // 调试信息保存方式，1 保存到控制台，2 保存到日志文件，3 同时保存到控制台和日志文件
  'info_save_manner' => Db::DEBUG_SAVE_CONSOLE | Db::DEBUG_SAVE_LOGGER,
  // XA 两阶段提交事务配置（Db::startXaTransaction 跨通道原子提交）
  'xa' => [
    // 是否开启自动恢复
    'auto_recovery' => false,
    // journal 表所在通道名（提交意图日志，崩溃恢复的决策依据），
    // 必须是支持 XA 的 MySQL 通道；留空或未配置时使用默认通道
    'journal_channel' => env('DATABASE_XA_JOURNAL_CHANNEL', 'default'),
    // journal 表名（首次使用时自动建表）
    'journal_table' => env('DATABASE_XA_JOURNAL_TABLE', 'viswoole_xa_journal'),
  ],
  // 通道列表
  'channels' => [
    'default' => [
      // 驱动类，必须继承 Viswoole\Database\Channel
      'driver' => PDOChannel::class,
      // PDOChannel 通道构造参数
      'options' => [
        'host' => env('DATABASE_HOST', '127.0.0.1'),
        'port' => (int)env('DATABASE_PORT', 3306),
        'database' => env('DATABASE_NAME', ''),
        'username' => env('DATABASE_USER', 'root'),
        'password' => env('DATABASE_PASSWORD', '123456')
      ]
    ]
  ]
];
