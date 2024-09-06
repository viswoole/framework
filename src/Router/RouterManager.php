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

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use Viswoole\Core\App;
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
   * @param App $app
   */
  public function __construct(protected readonly App $app)
  {
    $this->app->bind(self::class, $this);
    // 触发路由初始化事件，其他模块可以监听该事件注册路由
    $this->app->event->emit('RouteInit');
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
    $loadPaths = config('router.route_config_files', []);
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
    $rootPath = $this->app->getRootPath() . DIRECTORY_SEPARATOR;
    $directory = $rootPath . 'app/Controller';
    // 列出指定路径中的文件和目录
    $controllers = $this->getAllPhpFiles($directory);
    foreach ($controllers as $controller) {
      [$fullClass, $className] = $this->getNamespace($controller, $rootPath);
      if (class_exists($fullClass)) {
        $refClass = new ReflectionClass($fullClass);
      } else {
        continue;
      }
      // 获取类路由注解
      $classAttributes = $refClass->getAttributes(RouteController::class);
      if (empty($classAttributes)) {
        $classAttributes = $refClass->getAttributes(AutoRouteController::class);
        // 如果不存在路由注解则跳过
        if (empty($classAttributes)) continue;
      }
      /** @var RouteController|AutoRouteController $controller 控制器路由注解实例 */
      $controller = $classAttributes[0]->newInstance();
      // 判断是否设置了描述
      if (!isset($controller->options['describe'])) {
        $controller->options['describe'] = $this->getDocComment($refClass);
      }
      /** 是否为自动路由 */
      $isAutoRoute = $controller instanceof AutoRouteController;
      // 如果类路由注解的paths设置为null则默认为类名称
      if ($controller->paths === null) $controller->paths = $className;
      /** 路由收集器 */
      $RouteCollector = self::collector($controller->server);
      /** 分组路由实例 */
      $group = $RouteCollector->group($controller->paths, function () {
      })->options($controller->options);
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
        // 获取方法注解
        $methodAttributes = $method->getAttributes(RouteMapping::class);
        // 构建处理方法
        $handler = $method->isStatic() ?
          $refClass->getName() . '::' . $method->getName()
          : [$refClass->getName(), $method->getName()];
        // 如果没有设置路由注解，且该类为自动路由则创建路由
        if (empty($methodAttributes) && $isAutoRoute) {
          // 自动路由
          // 创建新的路由项
          $routeItem = new RouteItem($method->getName(), $handler, $group->getOptions());
          // 设置描述
          $routeItem->describe($this->getDocComment($method));
          // 添加到组的子路由中
          $group->addItem($routeItem);
        } elseif (isset($methodAttributes[0])) {
          // 处理设置了路由注解的方法
          /** @var RouteMapping $methodAnnotationRoute 注解路由 */
          $methodAnnotationRoute = $methodAttributes[0]->newInstance();
          // 设置描述
          if (!isset($methodAnnotationRoute->options['describe'])) {
            $methodAnnotationRoute->options['describe'] = $this->getDocComment($method);
          }
          $path = $methodAnnotationRoute->paths ?: $method->getName();
          // 创建新的路由项
          $routeItem = new RouteItem($path, $handler, $group->getOptions());
          // 批量设置选项
          $routeItem->options($methodAnnotationRoute->options);
          // 添加到组的子路由中
          $group->addItem($routeItem);
        }
      }
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
   * @return string[]
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
    return $this->serverRouteCollector[$serverName] = new RouteCollector($this->app);
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
    $this->app->event->emit('RouteLoaded');
  }

  /**
   * 获取api结构
   *
   * @access public
   * @param string|null $serverName 服务名称，如果为空则获取全部
   * @return array 如果$serverName为空，返回值数组的键是服务名称，值是路由结构数组，否则返回路由结构数组
   * @see RouteController::getShape() 具体数组结构
   */
  public function getApiShape(?string $serverName = null): array
  {
    if (empty($this->serverRouteCollector)) return [];
    $apiShape = [];
    if (empty($serverName)) {
      foreach ($this->serverRouteCollector as $serverName => $collector) {
        $apiShape[$serverName] = $collector->getApiShape();
      }
    } else {
      if (!isset($this->serverRouteCollector[$serverName])) throw new InvalidArgumentException(
        "not found $serverName route collector"
      );
      $apiShape = $this->serverRouteCollector[$serverName]->getApiShape();
    }
    return $apiShape;
  }
}
