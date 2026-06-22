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
 * 服务提供者抽象基类，定义服务的注册与启动生命周期
 */
abstract class Provider
{
  /**
   * @var string[] 批量绑定的服务映射，键为服务名，值为实现类名
   */
  public array $bindings = [];

  /**
   * @param App $app 应用容器实例
   */
  public function __construct(protected App $app)
  {
  }

  /**
   * 启动服务，在所有服务注册完成后调用，用于初始化或解析已注册的服务
   *
   * 示例：
   * ```php
   * $this->app->make('服务名');
   * ```
   */
  abstract public function boot(): void;

  /**
   * 注册服务绑定到容器，在启动之前调用
   *
   * 示例：
   * ```php
   * $this->app->bind('服务名', '类名');
   * ```
   */
  abstract public function register(): void;
}
