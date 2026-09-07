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

namespace Viswoole\Core;

use Swoole\Table;
use Viswoole\Cache\CacheManager;
use Viswoole\Cache\CacheService;
use Viswoole\Core\Service\MiddlewareService;
use Viswoole\Core\Service\Provider;
use Viswoole\Database\DbService;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Contract\ResponseInterface;
use Viswoole\HttpServer\HttpService;
use Viswoole\Log\LogManager;
use Viswoole\Log\LogService;
use Viswoole\Router\Router;
use Viswoole\Router\RouterService;

/**
 * 应用容器
 *
 * 框架全局唯一的容器实例，负责服务注册、初始化与生命周期管理。
 *
 * @property Env $env 环境变量管理实例
 * @property Config $config 配置管理实例
 * @property Console $console 控制台管理实例
 * @property LogManager $log 日志控制器
 * @property Event $event 事件管理器
 * @property Server $server 应用控制器
 * @property CacheManager $cache 缓存管理器
 * @property Middleware $middleware 中间件管理器
 * @property Router $router 路由管理器
 * @property ResponseInterface $response HTTP响应对象
 * @property RequestInterface $request HTTP请求对象
 */
class App extends Container
{
  /**
   * 框架版本号
   */
  public const string VERSION = '1.5.1';
  /**
   * @var App 当前应用容器实例（单例）
   */
  protected static self $instance;
  /**
   * @var array<string,string|Closure> 接口标识映射
   */
  protected array $bindings = [
    'app' => App::class,
    'env' => Env::class,
    'config' => Config::class,
    'console' => Console::class,
    'event' => Event::class,
    'server' => Server::class
  ];
  /**
   * @var string[] 服务列表
   */
  protected array $services = [
    LogService::class,
    CacheService::class,
    MiddlewareService::class,
    RouterService::class,
    HttpService::class,
    DbService::class
  ];
  /**
   * @var Table 基于 Swoole 共享内存的配置表，用于跨 Worker 进程同步 debug 状态
   */
  private Table $_config;
  /**
   * @var float 应用启动时间戳（含微秒精度）
   */
  private float $startRunTime;

  protected function __construct()
  {
    // 确保项目根路径已定义
    $this->getRootPath();
    $this->startRunTime = microtime(true);
    // 兼容swoole原生服务对象注入
    $this->bindings[\Swoole\Server::class] = function () {
      return $this->server->getServer();
    };
    $this->_config = new Table(1);
    $this->_config->column('debug', Table::TYPE_INT, 4);
    $this->_config->create();
    self::$instance = $this;
    $this->bind(App::class, $this);
    $this->initialize();
    $this->event->emit(FrameworkEvent::AppInitialized);
  }

  /**
   * 获取项目根路径，首次调用时定义 BASE_PATH 常量
   *
   * @return string 项目根目录绝对路径（不含末尾分隔符）
   */
  public function getRootPath(): string
  {
    if (!defined('BASE_PATH')) {
      $dir = realpath(__DIR__);
      // src/Core 向上 2 级是框架根目录
      $frameworkRoot = dirname($dir, 2);
      // 作为依赖包安装时结构为 app/vendor/组织名/包名，框架根目录向上 2 级为 vendor 目录；
      // 框架自身开发时框架根目录即应用根目录
      $appRoot = basename(dirname($frameworkRoot, 2)) === 'vendor'
        ? dirname($frameworkRoot, 3)
        : $frameworkRoot;
      define('BASE_PATH', $appRoot);
    }
    return rtrim(BASE_PATH, DIRECTORY_SEPARATOR);
  }

  /**
   * 初始化应用：设置 debug 模式、时区，并注册所有服务提供者
   */
  protected function initialize(): void
  {
    // 初始化配置
    $this->setDebug($this->config->get('app.debug', false));
    // 系统默认时区
    date_default_timezone_set(
      $this->config->get('app.default_timezone', 'Asia/Shanghai')
    );
    // 注册服务
    $this->loadService();
  }

  /**
   * 设置 debug 模式，通过 Swoole\Table 写入共享内存以影响所有 Worker 进程
   *
   * @param bool $debug 是否开启调试模式
   */
  public function setDebug(bool $debug): void
  {
    $this->_config->set('config', ['debug' => $debug ? 1 : 0]);
  }

  /**
   * 加载并启动所有服务提供者，合并框架默认、用户配置和依赖包三处注册的服务
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
   * 获取 Composer 依赖包目录路径
   *
   * @return string vendor 目录绝对路径
   */
  public function getVendorPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . 'vendor';
  }

  /**
   * 获取应用容器单例，首次调用时自动创建
   *
   * @return App 应用容器实例
   */
  public static function factory(): App
  {
    if (!isset(self::$instance)) new static();
    return self::$instance;
  }

  /**
   * 获取应用已运行时长
   *
   * @return float 自启动以来的秒数（含微秒精度）
   */
  public function getUptime(): float
  {
    return microtime(true) - $this->startRunTime;
  }

  /**
   * 获取应用启动时间戳
   *
   * @return float 启动时的 Unix 时间戳（含微秒精度）
   */
  public function getStartRunTime(): float
  {
    return $this->startRunTime;
  }

  /**
   * 获取框架版本号
   *
   * @return string 语义化版本号
   */
  public function getVersion(): string
  {
    return self::VERSION;
  }

  /**
   * 获取配置文件目录路径
   *
   * @return string config 目录绝对路径
   */
  public function getConfigPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . 'config';
  }

  /**
   * 获取应用业务代码目录路径
   *
   * @return string app 目录绝对路径
   */
  public function getAppPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . 'app';
  }

  /**
   * 获取环境变量文件路径
   *
   * @return string .env 文件绝对路径
   */
  public function getEnvPath(): string
  {
    return $this->getRootPath() . DIRECTORY_SEPARATOR . '.env';
  }

  /**
   * 判断当前是否处于 debug 调试模式，从 Swoole 共享内存表中读取
   *
   * @return bool 开启调试返回 true
   */
  public function isDebug(): bool
  {
    $config = $this->_config->get('config');
    return is_array($config) && !empty($config['debug']);
  }

  /**
   * 析构时触发 AppDestroying 事件，供服务清理资源
   */
  public function __destruct()
  {
    $this->event->emit(FrameworkEvent::AppDestroying);
  }
}
