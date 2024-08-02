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

use Viswoole\Core\Facade;

/**
 * 配置文件管理类
 *
 * @method static string formatConfigKey(string $key) 格式化key
 * @method static bool has(string $name) 检测配置是否存在
 * @method static mixed get(?string $name = null, mixed $default = null) 获取配置参数 name为null则获取所有配置
 * @method static void set(array|string $key, mixed $value = null) 设置或更新配置，仅在当前进程中下有效，重启进程则会丢失。
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Core\\Facade\\Config
 */
class Config extends Facade
{

  /**
   * @inheritDoc
   */
  #[\Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Core\Config::class;
  }
}
