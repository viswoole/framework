<?php
// +----------------------------------------------------------------------
// | 全局事件监听注册
// | return['event'=>listen::class|'event'=>callback]
// +----------------------------------------------------------------------

declare (strict_types=1);

return [
  // 应用开始初始化
  'AppInit' => [],
  // 应用初始化完成
  'AppInitialized' => [],
  // 应用销毁即将销毁
  'AppDestroy' => [],
  // SWOOLE服务创建完成
  'ServerCreate' => [],
  // SWOOLE服务启动
  'ServerStart' => []
];
