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

namespace Viswoole\Core\Service;

use ViSwoole\Core\App;

abstract class ServiceProvider
{
  /**
   * @var string[] 重写该属性，可批量注册服务
   */
  public array $bindings = [];

  public function __construct(protected App $app)
  {
  }

  /**
   * 该方法是在所有系统服务都绑定完毕过后调用，可以在此方法内注册路由，监听事件等
   *
   * @return void
   */
  abstract public function boot(): void;

  /**
   * 该方法会在服务注册时调用，在该方法内通过$this->app->bind('服务名', '服务类名');
   *
   * @return void
   */
  abstract public function register(): void;
}
