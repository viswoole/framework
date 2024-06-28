<?php
// +----------------------------------------------------------------------
// | 系统配置
// +----------------------------------------------------------------------

declare (strict_types=1);

return [
  // 默认时区
  'default_timezone' => env('default_timezone', 'Asia/Shanghai'),
  // 是否开启调试模式
  'debug' => env('app_debug', true),
  // 服务提供者注册
  'providers' => [],
  // 命令处理程序注册
  'commands' => []
];
