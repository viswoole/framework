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

  function __construct(protected readonly App $app)
  {
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
    //列出指定路径中的文件和目录
    $controllers = getAllPhpFiles($directory);
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
      /** @var $controller RouteController|AutoRouteController 控制器路由注解实例 */
      $controller = $classAttributes[0]->newInstance();
      // 如果指定了服务，且服务名称非当前正在运行的服务，则跳过解析
      if ($controller->server !== null && $controller->server !== $this->app->server->getName()) {
        continue;
      }
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
        if (empty($methodAttributes) && $isAutoRoute) {
          // 自动路由
          // 创建新的路由项
          $routeItem = new RouteItem($method->getName(), $handler, $group->getOptions());
          // 设置描述
          $routeItem->describe($this->getDocComment($method));
          // 添加到组的子路由中
          $group->addItem($routeItem);
        } elseif (isset($methodAttributes[0])) {
          /** @var $methodAnnotationRoute RouteMapping 注解路由 */
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
      if (preg_match('/^\s+\*\s+(.+)$/m', $classDocComment, $matches)) {
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
    if (is_null($serverName)) $serverName = $this->app->server->getName();
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
}
