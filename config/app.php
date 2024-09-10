<?php
// +----------------------------------------------------------------------
// | 系统配置
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\Core\Service\TaskService;

return [
  // 默认时区
  'default_timezone' => env('default_timezone', 'Asia/Shanghai'),
  // 是否开启调试模式
  'debug' => env('app_debug', true),
  // 服务提供者注册
  'providers' => [
    // swoole异步任务管理服务 如果不使用可以删除
    TaskService::class
  ],
  // 命令处理程序注册
  'commands' => []
];
