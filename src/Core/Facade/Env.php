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
 * Env管理类
 *
 * @method static void set(array|string $env, mixed $value = null) 设置环境变量值
 * @method static bool has(string $name) 检测是否存在环境变量
 * @method static mixed get(?string $name = null, mixed $default = null) 获取环境变量值(可获取用户环境变量和系统环境变量)
 * @method static mixed getEnv(string $name, mixed $default = null) 获取环境变量(仅能获取系统缓存变量)
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Core\\Facades\\Env
 */
class Env extends Facade
{

  /**
   * @inheritDoc
   */
  #[\Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Core\Env::class;
  }
}
