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

namespace Viswoole\Router;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Event;
use Viswoole\Router\Annotation\AutoRouteController;
use Viswoole\Router\Annotation\RouteController;
use Viswoole\Router\Annotation\RouteMapping;

/**
 * 路由管理器
 */
class RouterManager
{
  /**
   * @var RouteCollector[] 服务路由器实例
   */
  protected array $serverRouteCollector = [];
  /**
   * @var bool 缓存路由
   */
  private bool $cache;

  /**
   * @param Event $event
   * @param Config $config
   */
  public function __construct(private readonly Event $event, private readonly Config $config)
  {
    App::factory()->bind(self::class, $this);
    $this->cache = $config->get('router.cache.enable', false);
    // 触发路由初始化事件，其他模块可以监听该事件注册路由
    $event->emit('RouteInit');
    $this->loadConfigRoute();
    $this->loadAnnotationRoute();
    $this->parseRoute();
  }

  /**
   * 加载路由配置
   *
   * @return void
   */
  private function loadConfigRoute(): void
  {
    $loadPaths = $this->config->get('router.route_config_files', []);
    foreach ($loadPaths as $file) {
      require_once $file;
    }
  }

  /**
   * 加载注解路由
   *
   * @return void
   */
  private function loadAnnotationRoute(): void
  {
    $rootPath = getRootPath() . DIRECTORY_SEPARATOR;
    $directory = $rootPath . 'app/Controller';
    // 列出指定路径中的文件和目录
    $controllers = $this->getAllPhpFiles($directory);
    foreach ($controllers as $controller) {
      [$fullClass] = $this->getNamespace($controller, $rootPath);
      $hash = null;
      // 获取路由缓存
      if ($this->cache) {
        // 类文件哈希值
        $hash = hash_file('md5', $controller);
        $cacheRouteInfo = RouteCacheTool::getCache($fullClass, $hash);
        if (is_array($cacheRouteInfo)) {
          $this->collector($cacheRouteInfo['server'])->recordRouteItem($cacheRouteInfo['route']);
          continue;
        }
      }
      // 没有缓存，则解析路由
      $routeInfo = $this->parseController($controller, $rootPath);
      // 如果没有解析到路由则跳过
      if (empty($routeInfo)) continue;
      // 记录路由
      $this->collector($routeInfo['server'])->recordRouteItem($routeInfo['route']);
      // 如果hash不为null则缓存路由
      if (!$hash) continue;
      RouteCacheTool::setCache($fullClass, $hash, $routeInfo['server'], $routeInfo['route']);
    }
  }

  /**
   * 获取所有php文件
   *
   * @param string $dir
   * @return array
   */
  private function getAllPhpFiles(string $dir): array
  {
    $phpFiles = [];
    // 打开目录
    if ($handle = opendir($dir)) {
      $dir = rtrim($dir, DIRECTORY_SEPARATOR);
      // 逐个检查目录中的条目
      while (false !== ($entry = readdir($handle))) {
        if ($entry != '.' && $entry != '..') {
          $path = $dir . '/' . $entry;

          // 如果是目录，递归调用该函数
          if (is_dir($path)) {
            $phpFiles = array_merge($phpFiles, $this->getAllPhpFiles($path));
          } elseif (pathinfo($path, PATHINFO_EXTENSION) == 'php') {
            // 如果是.php文件，添加到结果数组中
            $phpFiles[] = $path;
          }
        }
      }

      // 关闭目录句柄
      closedir($handle);
    }

    return $phpFiles;
  }

  /**
   * 获取控制器完全限定名称
   *
   * @param string $controller
   * @param string $rootPath
   * @return array{0:string,1:string} [0=>完全限定名称,1=>类名称]
   */
  private function getNamespace(string $controller, string $rootPath): array
  {
    // 获得类名称
    $className = basename($controller, '.php');
    // 获得命名空间
    $classNamespace = str_replace($rootPath, '', $controller);
    $classNamespace = preg_replace('#^app/#', 'App/', dirname($classNamespace));
    $classNamespace = str_replace('/', '\\', $classNamespace);
    // 类完全限定名称Class::class
    return [$classNamespace . '\\' . $className, $className];
  }

  /**
   * 获取路由线路收集器实例
   *
   * @access public
   * @param string|null $serverName
   * @return RouteCollector
   */
  public function collector(string $serverName = null): RouteCollector
  {
    if (empty($serverName)) $serverName = SERVER_NAME;
    if (isset($this->serverRouteCollector[$serverName])) return $this->serverRouteCollector[$serverName];
    return $this->serverRouteCollector[$serverName] = invoke(RouteCollector::class);
  }

  /**
   * 解析控制器
   *
   * @param string $file 控制器文件
   * @param string $rootPath 根目录
   * @return array{server:string|null,route:RouteGroup}|null
   */
  private function parseController(string $file, string $rootPath): ?array
  {
    [$fullClass] = $this->getNamespace($file, $rootPath);
    if (class_exists($fullClass)) {
      $refClass = new ReflectionClass($fullClass);
      $className = $refClass->getShortName();
    } else {
      return null;
    }
    // 获取路由注解属性
    $classAttributes = $refClass->getAttributes(
      RouteController::class, ReflectionAttribute::IS_INSTANCEOF
    );
    // 没有路由控制器注解属性则不解析
    if (empty($classAttributes)) return null;
    // 类完全名称md5值作为路由分组名称
    $groupId = md5($fullClass);
    /** @var RouteController|AutoRouteController $controller 控制器路由注解实例 */
    $controller = $classAttributes[0]->newInstance();
    // 服务名称
    $serverName = $controller->server;
    // 判断是否设置了描述
    if (!isset($controller->title)) {
      $controller->options['title'] = $this->getDocComment($refClass);
    }
    /** 是否为自动路由 */
    $isAutoRoute = $controller instanceof AutoRouteController;
    // 如果类路由注解的paths设置为null则默认为类名称
    if ($controller->paths === null) $controller->paths = $className;
    /** 分组路由实例 */
    $group = new RouteGroup($controller->paths, [], id: $groupId);
    // 类的全部方法
    $methods = $refClass->getMethods();
    // 处理类的方法
    foreach ($methods as $method) {
      // 判断是否需要创建路由
      $isCreate = $method->isPublic()
        && !$method->isConstructor()
        && !$method->isAbstract()
        && !$method->isDestructor();
      // 不需要创建路由则跳过
      if (!$isCreate) continue;
      // 方法id
      $methodId = md5($fullClass . $method->getName());
      // 获取方法注解
      $methodAttributes = $method->getAttributes(RouteMapping::class);
      // 构建处理方法
      $handler = $method->isStatic() ?
        $refClass->getName() . '::' . $method->getName()
        : [$refClass->getName(), $method->getName()];
      // 如果没有设置路由注解，且该类为自动路由则创建路由
      if (empty($methodAttributes) && $isAutoRoute) {  // 自动路由
        // 创建新的路由项
        $routeItem = new RouteItem(
          $method->getName(), $handler, $group->getOptions(), id: $methodId
        );
        // 设置描述
        $routeItem->title($this->getDocComment($method));
        // 添加到组的子路由中
        $group->addItem($routeItem);
      } elseif (isset($methodAttributes[0])) {
        // 处理设置了路由注解的方法
        /** @var RouteMapping $methodAnnotationRoute 注解路由 */
        $methodAnnotationRoute = $methodAttributes[0]->newInstance();
        // 设置描述
        if (!isset($methodAnnotationRoute->title)) {
          $methodAnnotationRoute->options['title'] = $this->getDocComment($method);
        }
        $path = $methodAnnotationRoute->paths ?: $method->getName();
        // 创建新的路由项
        $routeItem = new RouteItem($path, $handler, $group->getOptions(), id: $methodId);
        // 批量设置选项
        $routeItem->options($methodAnnotationRoute->options);
        // 添加到组的子路由中
        $group->addItem($routeItem);
      }
    }
    return ['server' => $serverName, 'route' => $group];
  }

  /**
   * 获取注释
   *
   * @param ReflectionClass|ReflectionMethod $reflector
   * @return string
   */
  private function getDocComment(ReflectionClass|ReflectionMethod $reflector): string
  {
    $classDocComment = $reflector->getDocComment();
    if ($classDocComment) {
      if (preg_match('/^\s+\*\s+([^@\n][^\n]*)$/m', $classDocComment, $matches)) {
        $classDocComment = trim($matches[1]);
      } else {
        $classDocComment = '';
      }
    } else {
      $classDocComment = '';
    }
    return $classDocComment;
  }

  /**
   * 解析服务路由
   * @return void
   */
  private function parseRoute(): void
  {
    foreach ($this->serverRouteCollector as $collector) {
      $collector->parseRoute();
    }
    $this->event->emit('RouteLoaded');
  }
}
