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

use Viswoole\Core\App;
use Viswoole\Core\Exception\NotFoundException;

if (!function_exists('getRootPath')) {
  /**
   * 获取项目根目录,结尾不带/
   * @return string
   */
  function getRootPath(): string
  {
    return App::factory()->getRootPath();
  }
}
if (!function_exists('getVendorPath')) {
  /**
   * 获取依赖仓库路径,结尾不带/
   * @return string
   */
  function getVendorPath(): string
  {
    return App::factory()->getVendorPath();
  }
}
if (!function_exists('getConfigPath')) {
  /**
   * 获取配置仓库路径,结尾不带/
   * @return string
   */
  function getConfigPath(): string
  {
    return App::factory()->getConfigPath();
  }
}
if (!function_exists('getAppPath')) {
  /**
   * 获取服务或容器
   *
   * @return string
   */
  function getAppPath(): string
  {
    return App::factory()->getAppPath();
  }
}
if (!function_exists('app')) {
  /**
   * 获取服务或容器
   *
   * @param string|null $name 标识或接口,不传返回容器实例
   * @return mixed
   * @throws NotFoundException
   */
  function app(?string $name = null): mixed
  {
    if (empty($name)) return App::factory();
    return App::factory()->get($name);
  }
}
if (!function_exists('env')) {
  /**
   * 获取环境变量的值
   *
   * @param string|null $key 环境变量名（支持二级 .号分割）
   * @param mixed|null $default 默认值
   * @return mixed
   */
  function env(?string $key, mixed $default = null): mixed
  {
    return App::factory()->env->get($key, $default);
  }
}
if (!function_exists('app_debug')) {
  /**
   * 判断是否开启了debug模式
   *
   * @return bool
   */
  function app_debug(): bool
  {
    return App::factory()->isDebug();
  }
}
if (!function_exists('config')) {
  /**
   * 获取配置
   *
   * @param string|null $name 配置名（支持二级 .号分割）
   * @param mixed|null $default 默认值
   * @return mixed
   */
  function config(string $name = null, mixed $default = null): mixed
  {
    return App::factory()->config->get($name, $default);
  }
}