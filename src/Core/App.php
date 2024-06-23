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

use Viswoole\Core\Service\Provider;

/**
 * App应用管理中心
 *
 * @property Env $env 环境变量管理实例
 * @property Config $config 配置管理实例
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
  ];
  /**
   * @var Provider[] 服务列表
   */
  protected array $services = [];

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
    $this->registerService();
    // 启动服务
    $this->bootService();
  }

  /**
   * 注册服务
   *
   * @access protected
   * @return void
   */
  protected function registerService(): void
  {
    $services = $this->config->get('app.services', []);
    $depPath = $this->getVendorPath() . '/services.php';
    // 依赖包注册的服务
    $dependentServices = is_file($depPath) ? require $depPath : [];
    $services = array_merge($services, $dependentServices);
    // 遍历服务绑定进容器
    foreach ($services as $service) {
      if (is_string($service)) $service = new $service($this);
      if (property_exists($service, 'bindings')) {
        $this->bindings = array_merge($this->bindings, $service->bindings);
      }
      $service->register();
      $this->services[] = $service;
    }
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
    !defined('BASE_PATH') && define('BASE_PATH', dirname(realpath(__DIR__), 4));
    return rtrim(BASE_PATH, DIRECTORY_SEPARATOR);
  }

  /**
   * 初始化服务
   *
   * @return void
   */
  protected function bootService(): void
  {
    foreach ($this->services as $service) $service->boot();
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
