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

use Viswoole\Core\App;

/**
 * 服务提供者抽象类
 */
abstract class Provider
{
  /**
   * @var string[] 重写该属性，可批量注册服务
   */
  public array $bindings = [];

  /**
   * @param App $app
   */
  public function __construct(protected App $app)
  {
  }

  /**
   * 该方法用于启动/初始化服务
   *
   * Example:
   * ```php
   * $this->app->make('服务名');
   * ```
   *
   * @return void
   */
  abstract public function boot(): void;

  /**
   * 该方法用于向应用容器中注册服务
   *
   * Example:
   * ```php
   * $this->app->bind('服务名', '类名');
   * ```
   *
   * @return void
   */
  abstract public function register(): void;
}
