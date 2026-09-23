<?php
// +----------------------------------------------------------------------
// | 事件监听注册
// +----------------------------------------------------------------------

declare(strict_types=1);

use Viswoole\Core\Facade\Event;
use Viswoole\Core\FrameworkEvent;

// 注册事件监听
Event::on(FrameworkEvent::AppInitialized, function () {
  // 应用初始化完成
});
