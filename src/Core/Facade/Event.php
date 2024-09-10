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

declare (strict_types=1);

namespace Viswoole\Core\Facade;

use Override;
use Viswoole\Core\Facade;

/**
 * Event事件管理器
 *
 * @method static array|string on(string $event, callable|string $handle, int $limit = 0) 监听事件
 * @method static void emit(string $event, array $arguments = []) 触发事件
 * @method static void off(string $event, string $id = null) 关闭某个事件的监听器，如果id为null，则关闭该事件的所有监听器
 * @method static void offAll() 清除所有监听器
 * @method static array getEvents() 获取已监听的事件
 */
class Event extends Facade
{

  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Core\Event::class;
  }
}
