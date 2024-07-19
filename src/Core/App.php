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

namespace Viswoole\Core;

use Viswoole\Cache\Cache;
use Viswoole\Cache\CacheService;
use Viswoole\Core\Service\Provider;
use Viswoole\Log\LogManager;

/**
 * App应用管理中心
 *
 * @property Env $env 环境变量管理实例
 * @property Config $config 配置管理实例
 * @property Console $console 控制台管理实例
 * @property LogManager $log 日志控制器
 * @property Event $event 事件管理器
 * @property Server $server 应用控制器
 * @property Cache $cache 缓存管理器
 * @property Middleware $middleware 中间件管理器
 */
class App extends Container
{
  protected static self $instance;
  /**
   * @var array<string,string> 接口标识映射
   */
  protected array $bindings = [
    'app' => App::class,
    'env' => Env::class,
    'config' => Config::class,
    'console' => Console::class,
    'log' => LogManager::class,
    'event' => Event::class,
    'server' => Server::class,
    'middleware' => Middleware::class
  ];
  /**
   * @var string[] 服务列表
   */
  protected array $services = [
    CacheService::class
  ];

  protected function __construct()
  {
    self::$instance = $this;
    $this->bind(App::class, $this);
    $this->initialize();
  }

  private function initialize(): void
  {
    // 系统默认时区
    date_default_timezone_set(
      $this->config->get(
        'app.default_timezone',
        'Asia/Shanghai'
      )
    );
    // 注册服务
    $this->loadService();
  }

  /**
   * 加载服务
   *
   * @return void
   */
  protected function loadService(): void
  {
    $services = $this->config->get('app.services', []);
    $depPath = $this->getVendorPath() . '/services.php';
    // 依赖包注册的服务
    $dependentServices = is_file($depPath) ? require $depPath : [];
    // 合并服务
    $this->services = array_merge($this->services, $services, $dependentServices);
    /**
     * @var Provider $service
     */
    $instances = [];
    // 遍历注册服务
    foreach ($this->services as $service) {
      /**
       * @var Provider $instance 反射得到的服务提供者实例
       */
      $instance = $this->invokeClass($service);
      if (property_exists($instance, 'bindings')) {
        $this->bindings = array_merge($this->bindings, $instance->bindings);
      }
      $instance->register();
      $instances[] = $instance;
    }
    // 启动服务
    foreach ($instances as $instance) $instance->boot();
    unset($instances);
  }

  /**
   * 获取vendor路径
   *
   * @return string
   */
  public function getVendorPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . 'vendor';
  }

  /**
   * 获取项目根路径
   *
   * @access public
   * @return string
   */
  public function getRootPath(): string
  {
    !defined('BASE_PATH') && define('BASE_PATH', dirname(realpath(__DIR__), 3));
    return rtrim(BASE_PATH, DIRECTORY_SEPARATOR);
  }

  /**
   * 工厂单例模式
   *
   * @return App
   */
  public static function factory(): App
  {
    if (!isset(self::$instance)) new static();
    return self::$instance;
  }

  /**
   * 获取config路径
   *
   * @access public
   * @return string
   */
  public function getConfigPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . 'config';
  }

  /**
   * 获取app路径
   *
   * @access public
   * @return string
   */
  public function getAppPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . 'app';
  }

  /**
   * 获取env路径
   *
   * @access public
   * @return string
   */
  public function getEnvPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . '.env';
  }

  /**
   * 是否debug调试模式
   *
   * @return bool
   */
  public function isDebug(): bool
  {
    return $this->config->get('app.debug', false);
  }

  /**
   * 设置是否启用debug模式，在请求中设置仅对当前请求的worker进程生效
   *
   * @access public
   * @param bool $debug
   * @return void
   */
  public function setDebug(bool $debug): void
  {
    $this->config->set('app.debug', $debug);
  }
}
