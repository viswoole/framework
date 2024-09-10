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

use Viswoole\Cache\CacheManager;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Console\Output;
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
   * @return mixed|App
   * @throws NotFoundException
   */
  function app(?string $name = null): mixed
  {
    if (empty($name)) return App::factory();
    return App::factory()->get($name);
  }
}
if (!function_exists('isDebug')) {
  /**
   * 判断是否Debug环境
   *
   * @return bool
   */
  function isDebug(): bool
  {
    return App::factory()->isDebug();
  }
}
if (!function_exists('env')) {
  /**
   * 获取环境变量的值
   *
   * @param string|null $key 环境变量名（支持二级 .号分割）
   * @param mixed|null $default 默认值
   * @return mixed
   * @see \Viswoole\Core\Env::get()
   */
  function env(?string $key = null, mixed $default = null): mixed
  {
    return App::factory()->get('env')->get($key, $default);
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
   * @param mixed $default 默认值
   * @return mixed
   * @see Config::get()
   */
  function config(string $name = null, mixed $default = null): mixed
  {
    /**
     * @var Config $config
     */
    $config = App::factory()->get('config');
    return $config->get($name, $default);
  }
}
if (!function_exists('dump')) {
  /**
   * 打印变量
   *
   * @access public
   * @param mixed $data 变量内容
   * @param string $title 标题
   * @param string $color 颜色
   * @param int $backtrace 1为输出调用源，0为不输出
   * @return void
   */
  function dump(
    mixed  $data,
    string $title = 'variable output',
    string $color = Output::COLORS['GREEN'],
    int    $backtrace = 1
  ): void
  {
    Output::dump($data, $title, $color, $backtrace === 0 ? 0 : 2);
  }
}
if (!function_exists('echo_log')) {
  /**
   * 输出一条文本日志
   *
   * @param string|int $message 要输出的内容
   * @param string $label 标签
   * @param string|null $color 转义颜色,如果标签未映射颜色，且传入null，则使用默认颜色
   * @param int $backtrace 1为输出调用源，0为不输出
   * @return void
   */
  function echo_log(
    string|int $message,
    string     $label = 'SUCCESS',
    ?string    $color = null,
    int        $backtrace = 1
  ): void
  {
    Output::echo($message, $label, $color, $backtrace === 0 ? 0 : 2);
  }
}
if (!function_exists('cache')) {
  /**
   * 缓存助手函数
   *
   * @param string|null $key 缓存标识
   * @param mixed|null $value 缓存不存在时返回的默认值
   * @return mixed|CacheManager 如果缓存标识为null，则返回CacheManager实例
   */
  function cache(?string $key = null, mixed $value = null): mixed
  {
    if (is_null($key)) return App::factory()->cache;
    return App::factory()->cache->get($key, $value);
  }
}
if (!function_exists('invoke')) {
  /**
   * 调用函数或方法，依赖注入
   *
   * @param array|callable|string $callable
   * @param array $params
   * @return mixed
   */
  function invoke(array|callable|string $callable, array $params = []): mixed
  {
    return App::factory()->invoke($callable, $params);
  }
}
