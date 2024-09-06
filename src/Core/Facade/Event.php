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
 * @method static void on(string $event, callable|string $handle, int $limit = 0) 监听事件
 * @method static void emit(string $event, array $data = []) 触发事件
 * @method static void off(string $event, callable|string $handle = null) 清除事件，不传handle则清除所有
 * @method static void offAll() 清除所有事件
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
