<?php
// +----------------------------------------------------------------------
// | 全局事件监听注册
// | return['event'=>listen::class|'event'=>callback]
// +----------------------------------------------------------------------

declare (strict_types=1);

return [
  // 应用初始化
  'AppInit' => [],
  // 应用销毁
  'AppDestroyed' => [],
  // 服务器启动
  'ServerStart' => [],
  // 服务器停止
  'ServerShutdown' => []
];
