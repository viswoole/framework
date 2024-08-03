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

namespace Viswoole\Database\Facade;

use Viswoole\Core\Facade;
use Viswoole\Database\Driver\Contract\DriverInterface;
use Viswoole\Database\Manager\ChannelManager;

/**
 * 通道管理器
 */

/**
 * @method static DriverInterface channel(?string $name = null) 获取数据库通道
 * @method static bool hasChannel(string $channel_name) 判断通道是否存在
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Database\\Facade\\DbChannelManager
 */
class DbChannelManager extends Facade
{

  /**
   * 获取当前Facade对应类名
   *
   * @access protected
   * @return string
   */
  #[\Override] protected static function getMappingClass(): string
  {
    return ChannelManager::class;
  }
}
