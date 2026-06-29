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

declare(strict_types=1);

use Viswoole\Cache\CacheManager;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Console\Output;
use Viswoole\Core\Exception\NotFoundException;

if (!function_exists('getRootPath')) {
  /**
   * 获取项目根目录,结尾不带/
   *
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
   *
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
   * 获取app目录
   *
   * @return string
   */
  function getAppPath(): string
  {
    return App::factory()->getAppPath();
  }
}
if (!function_exists('getEnvPath')) {
  /**
   * 获取 .env 环境变量文件路径
   *
   * @return string .env 文件绝对路径
   */
  function getEnvPath(): string
  {
    return App::factory()->getEnvPath();
  }
}
if (!function_exists('app')) {
  /**
   * 获取容器实例或从容器中解析服务
   *
   * @param string|null $name 服务标识或接口名，null 时返回容器实例
   * @return mixed|App 容器实例或解析出的服务
   * @throws NotFoundException 服务不存在时抛出
   */
  function app(?string $name = null): mixed
  {
    if (empty($name)) return App::factory();
    return App::factory()->get($name);
  }
}
if (!function_exists('isDebug')) {
  /**
   * 判断当前是否为调试模式
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
   * 获取配置项的值，支持点号分隔的多级键名
   *
   * @param string|null $name 配置键名
   * @param mixed $default 键不存在时的默认值
   * @return mixed 配置值或默认值
   * @see Config::get()
   */
  function config(?string $name = null, mixed $default = null): mixed
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
  ): void {
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
  ): void {
    Output::echo($message, $label, $color, $backtrace === 0 ? 0 : 2);
  }
}
if (!function_exists('cache')) {
  /**
   * 缓存助手函数，获取缓存值或返回缓存管理器实例
   *
   * @param string|null $key 缓存键名，null 时返回 CacheManager 实例
   * @param mixed|null $value 键不存在时的默认值
   * @return mixed|CacheManager 缓存值或缓存管理器实例
   */
  function cache(?string $key = null, mixed $value = null): mixed
  {
    if (is_null($key)) return App::factory()->cache;
    return App::factory()->cache->get($key, $value);
  }
}
if (!function_exists('invoke')) {
  /**
   * 通过容器依赖注入调用函数或方法
   *
   * @param array|callable|string $callable 可调用的函数、方法或 [类, 方法] 数组
   * @param array $params 额外传入的参数
   * @return mixed 调用结果
   */
  function invoke(array|callable|string $callable, array $params = []): mixed
  {
    return App::factory()->invoke($callable, $params);
  }
}
if (!function_exists('bind')) {
  /**
   * 将接口或标识绑定到具体实现到容器
   *
   * @param string $abstract 接口名或标识
   * @param object|string $concrete 实现实例或类名
   */
  function bind(string $abstract, object|string $concrete): void
  {
    App::factory()->bind($abstract, $concrete);
  }
}
if (!function_exists('make')) {
  /**
   * 创建实例
   *
   * @param string $abstract 标识或接口
   * @param array $params 构造函数参数
   * @return mixed
   */
  function make(string $abstract, array $params = []): mixed
  {
    return App::factory()->make($abstract, $params);
  }
}
if (!function_exists('getVersion')) {
  /**
   * 获取框架当前版本号
   *
   * @return string 版本号
   */
  function getVersion(): string
  {
    return App::factory()->getVersion();
  }
}
