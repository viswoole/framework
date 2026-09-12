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
use Viswoole\Core\Config;
use Viswoole\Router\Annotation\AutoController;
use Viswoole\Router\Annotation\Controller;
use Viswoole\Router\Annotation\RouteMapping;
use Viswoole\Router\ApiDoc\DocCommentTool;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由装载器
 *
 * 负责将配置路由文件与控制器注解路由装载到路由收集器：
 * - 配置路由：require router.route_config_files 指定的文件（文件内经 Facade 注册路由）
 * - 注解路由：扫描 app/Controller 目录，解析 #[Controller]/#[AutoController]/#[RouteMapping]
 *   注解并生成路由组；router.cache.enable 时按"框架版本+文件哈希"读写路由缓存
 *
 * 装载到的路由组经 Collector::recordRouteItem 记录，最终由 Router::parseRoute
 * 完成分组装配与路由表写入
 */
final class RouteLoader
{
  /**
   * 装载配置路由与注解路由
   *
   * @param Collector $collector 路由收集器（Router 实例）
   * @param Config $config 框架配置实例
   * @param bool $cache 是否启用路由缓存（router.cache.enable）
   */
  public static function load(Collector $collector, Config $config, bool $cache): void
  {
    self::loadConfigRoute($config);
    self::loadAnnotationRoute($collector, $cache);
  }

  /**
   * 加载路由配置文件（通过 router.route_config_files 配置项指定）
   *
   * @param Config $config 框架配置实例
   */
  private static function loadConfigRoute(Config $config): void
  {
    $loadPaths = $config->get('router.route_config_files', []);
    foreach ($loadPaths as $file) {
      require_once $file;
    }
  }

  /**
   * 加载注解路由
   *
   * @param Collector $collector 路由收集器
   * @param bool $cache 是否启用路由缓存
   */
  private static function loadAnnotationRoute(Collector $collector, bool $cache): void
  {
    $rootPath = getRootPath() . DIRECTORY_SEPARATOR;
    $directory = $rootPath . 'app/Controller';
    // 列出指定路径中的文件和目录
    $controllers = RouterTool::getAllFiles($directory);
    foreach ($controllers as $controller) {
      [$fullClass] = RouterTool::getNamespace($controller, $rootPath);
      $hash = null;
      // 获取路由缓存
      if ($cache) {
        // 缓存哈希：框架版本号 + 类文件哈希，框架升级后旧缓存自动失效
        $hash = RouterTool::getCacheHash($controller);
        $cacheGroup = RouterTool::getCache(SERVER_NAME, $fullClass, $hash);
        if ($cacheGroup) {
          $collector->recordRouteItem($cacheGroup);
          continue;
        }
      }
      // 没有缓存，则解析路由
      $routeGroup = self::parseController($controller, $rootPath);
      // 如果没有解析到路由则跳过
      if (empty($routeGroup)) continue;
      // 记录路由
      $collector->recordRouteItem($routeGroup);
      // 如果hash不为null则缓存路由
      if (!$hash) continue;
      RouterTool::setCache(SERVER_NAME, $fullClass, $hash, $routeGroup);
    }
  }

  /**
   * 解析控制器
   *
   * @param string $file 控制器文件
   * @param string $rootPath 根目录
   * @return Group|null
   */
  private static function parseController(string $file, string $rootPath): ?Group
  {
    [$fullClass] = RouterTool::getNamespace($file, $rootPath);
    if (class_exists($fullClass)) {
      $refClass = new ReflectionClass($fullClass);
      $className = $refClass->getShortName();
    } else {
      return null;
    }
    // 获取路由注解属性
    $classAttributes = $refClass->getAttributes(
      Controller::class, ReflectionAttribute::IS_INSTANCEOF
    );
    // 没有路由控制器注解属性则不解析
    if (empty($classAttributes)) return null;
    /** @var Controller|AutoController $controller 控制器路由注解实例 */
    $controller = $classAttributes[0]->newInstance();
    // 服务名称
    $serverName = $controller->server ?? SERVER_NAME;
    // 判断服务名称是否匹配当前服务
    if (strtolower($serverName) !== strtolower(SERVER_NAME)) return null;
    // 判断是否设置了描述
    if (!isset($controller->title)) {
      $controller->title = DocCommentTool::extractDocTitle($refClass->getDocComment() ?: '');
    }
    /** 是否为自动路由 */
    $isAutoRoute = $controller instanceof AutoController;
    // 如果类路由注解的paths设置为null则默认为类名称
    if ($controller->prefix === null) $controller->prefix = $className;
    // 类完全名称md5值作为路由分组名称
    if (!$controller->id) $controller->id = RouterTool::generateHashId($fullClass);
    /**
     * @var Group $group 路由分组实例
     */
    $group = $controller->create([]);
    // 记录控制器类源码位置（相对项目根目录），供接口文档定位
    $group->setSourceLocation(
      RouterTool::relativeToRoot($refClass->getFileName()),
      $refClass->getStartLine()
    );
    // 类的全部方法
    $methods = $refClass->getMethods();
    if (!empty($methods)) self::parseMethod($methods, $isAutoRoute, $group);
    return $group;
  }

  /**
   * 解析方法
   *
   * @param ReflectionMethod[] $methods 方法列表
   * @param bool $isAutoRoute 是否自动路由
   * @param Group $group
   * @return void
   */
  public static function parseMethod(array $methods, bool $isAutoRoute, Group $group): void
  {
    if (empty($methods)) return;
    $class = $methods[0]->getDeclaringClass()->getName();
    foreach ($methods as $method) {
      // 判断是否需要创建路由
      $isCreate = $method->isPublic()
        && !$method->isConstructor()
        && !$method->isAbstract()
        && !$method->isDestructor();
      // 不需要创建路由则跳过
      if (!$isCreate) continue;
      $methodName = $method->getName();
      // 路由id
      $methodId = RouterTool::generateHashId($class . '::' . $methodName);
      // 获取方法注解
      $methodAttributes = $method->getAttributes(RouteMapping::class);
      // 方法文档注释
      $methodDocComment = $method->getDocComment() ?: '';
      // 构建处理方法
      $handler = $method->isStatic()
        ? $class . '::' . $method->getName()
        : [$class, $method->getName()];
      // 如果没有设置路由注解，且该类为自动路由则创建路由
      if (empty($methodAttributes)) {  // 自动路由
        if (!$isAutoRoute) continue;
        // 创建新的路由项
        $routeItem = new Route($method->getName(), $handler, $group, id: $methodId);
        // 设置标题
        $routeItem->setTitle(DocCommentTool::extractDocTitle($methodDocComment));
        // 设置描述
        $routeItem->setDescription(DocCommentTool::extractDocDescription($methodDocComment));
      } else {
        // 处理设置了路由注解的方法
        /** @var RouteMapping $methodAnnotationRoute 注解路由 */
        $methodAnnotationRoute = $methodAttributes[0]->newInstance();
        // 未声明标题时，从方法文档注释首行提取
        if (empty($methodAnnotationRoute->title)) {
          $methodAnnotationRoute->title = DocCommentTool::extractDocTitle($methodDocComment);
        }
        // 未声明描述时，从方法文档注释正文提取
        if (empty($methodAnnotationRoute->description)) {
          $methodAnnotationRoute->description = DocCommentTool::extractDocDescription(
            $methodDocComment
          );
        }
        // 如果没有设置路由路径则默认为方法名称
        if (empty($methodAnnotationRoute->prefix)) {
          $methodAnnotationRoute->prefix = $methodName;
        }
        // 设置路由id
        if (!$methodAnnotationRoute->id) $methodAnnotationRoute->id = $methodId;
        // 创建路由项
        $routeItem = $methodAnnotationRoute->create($handler, $group);
      }
      // 添加到组的子路由中
      $group->addItem($routeItem);
    }
  }
}
