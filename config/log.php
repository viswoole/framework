<?php
// +----------------------------------------------------------------------
// | 日志系统配置文件
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\Log\Drives\File;

return [
  // 默认通道
  'default' => 'file',
  // 类型指定写入通道，例如：['error'=>'email']
  'type_channel' => [],
  // 是否跟踪日志来源
  'trace_source' => true,
  // 是否同时将日志输出到控制台（只建议在开发环境中使用）
  'console' => false,
  // 日志驱动通道，可自行实现日志驱动需继承\Viswoole\Log\Drive类或实现\Viswoole\Log\Contract\DriveInterface接口
  'channels' => [
    'file' => File::class
  ]
];
