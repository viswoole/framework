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
use Viswoole\Core\Middleware;
use Viswoole\Router\Annotation\AutoRouteController;
use Viswoole\Router\Annotation\RouteController;
use Viswoole\Router\Annotation\RouteMapping;
use Viswoole\Router\ApiDoc\DocCommentTool;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\BaseRoute;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由收集器
 */
class Router extends Collector
{
  /**
   * @var array<string,string> 完全静态的路由item索引
   */
  protected array $staticRoute = [];
  /**
   * @var array<string,string> 动态参数的子节点
   */
  protected array $dynamicRoute = [];
  /**
   * @var bool 缓存路由
   */
  private bool $cache;

  /**
   * @param Event $event
   * @param Config $config
   * @param Middleware $middleware
   */
  public function __construct(
    private readonly Event      $event,
    private readonly Config     $config,
    private readonly Middleware $middleware
  )
  {
    App::factory()->bind(self::class, $this);
    // 触发路由初始化事件，其他模块可以监听该事件注册路由
    $this->event->emit('RouteInit');
    $this->cache = $config->get('router.cache.enable', false);
    $this->loadConfigRoute();
    $this->loadAnnotationRoute();
    $this->parseRoute();
    $this->event->emit('RouteLoaded');
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
        $cacheRouteInfo = RouteTool::getCache(SERVER_NAME . $fullClass, $hash);
        if (is_array($cacheRouteInfo)) {
          $this->recordRouteItem($cacheRouteInfo['route']);
          continue;
        }
      }
      // 没有缓存，则解析路由
      $routeInfo = $this->parseController($controller, $rootPath);
      // 如果没有解析到路由则跳过
      if (empty($routeInfo)) continue;
      // 记录路由
      $this->recordRouteItem($routeInfo['route']);
      // 如果hash不为null则缓存路由
      if (!$hash) continue;
      RouteTool::setCache(
        SERVER_NAME . $fullClass, $hash, $routeInfo['server'], $routeInfo['route']
      );
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
   * 解析控制器
   *
   * @param string $file 控制器文件
   * @param string $rootPath 根目录
   * @return array{server:string|null,route:Group}|null
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
    /** @var RouteController|AutoRouteController $controller 控制器路由注解实例 */
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
    $isAutoRoute = $controller instanceof AutoRouteController;
    // 如果类路由注解的paths设置为null则默认为类名称
    if ($controller->prefix === null) $controller->prefix = $className;
    // 类完全名称md5值作为路由分组名称
    if (!$controller->id) $controller->id = RouteTool::generateHashId($fullClass);
    /**
     * @var Group $group 路由分组实例
     */
    $group = $controller->create([]);
    // 类的全部方法
    $methods = $refClass->getMethods();
    if (!empty($methods)) $this->parseMethod($methods, $isAutoRoute, $group);
    return ['server' => $serverName, 'route' => $group];
  }

  /**
   * 解析方法
   *
   * @param ReflectionMethod[] $methods 方法列表
   * @param bool $isAutoRoute 是否自动路由
   * @param Group $group
   * @return void
   */
  private function parseMethod(array $methods, bool $isAutoRoute, Group $group): void
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
      $methodId = RouteTool::generateHashId($class . '::' . $methodName);
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
      } else {
        // 处理设置了路由注解的方法
        /** @var RouteMapping $methodAnnotationRoute 注解路由 */
        $methodAnnotationRoute = $methodAttributes[0]->newInstance();
        // 设置描述
        if (!isset($methodAnnotationRoute->title)) {
          $methodAnnotationRoute->title = DocCommentTool::extractDocTitle($methodDocComment);
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

  /**
   * 解析路由(最后执行)
   *
   * @return void
   * @access private
   */
  private function parseRoute(): void
  {
    // 对路由进行排序
    uasort($this->routes, function (BaseRoute $a, BaseRoute $b) {
      return $b->getSort() <=> $a->getSort();
    });
    foreach ($this->routes as $key => $item) {
      if ($parent = $item->getParentId()) {
        // 处理自定义父级路由依赖
        $routeGroup = $this->getRoute($parent);
        if ($routeGroup instanceof Group) {
          $routeGroup->addItem($item);
          unset($this->routes[$key]);
          continue;
        }
      }
      $this->register($item);
    }
  }

  /**
   * 注册路由
   *
   * @param Group|Route $route
   * @return void
   */
  private function register(Group|Route $route): void
  {
    if ($route instanceof Group) {
      $this->currentGroup = $route;
      foreach ($route->getItem() as $item) {
        $this->register($item);
      }
      $this->currentGroup = null;
    } else {
      foreach ($route->getPaths() as $path) {
        $this->insertRoute($path, $route->getCiteLink());
      }
    }
  }

  /**
   * 插入路由到node树
   * @param string $path
   * @param string $routeIndex
   * @return void
   */
  private function insertRoute(string $path, string $routeIndex): void
  {
    if (RouteTool::isVariable($path)) {
      $urlSegments = explode('/', $path);
      $urlSegments = array_filter($urlSegments, function ($value) {
        return $value !== '';
      });
      $regex = $this->convertRegex(
        $urlSegments,
        $this->getRoute($routeIndex)->getPatterns()
      );
      $this->addDynamicRoute($urlSegments, $regex, $routeIndex);
    } else {
      $this->addStaticRoute($path, $routeIndex);
    }
  }

  /**
   * 转换为正则
   * @param array $segments UrlPath段
   * @param array $patternRule 参数规则
   * @return string
   */
  private function convertRegex(array $segments, array $patternRule = []): string
  {
    $regexPattern = '';
    foreach ($segments as $segment) {
      //判断是否为变量字段
      if (RouteTool::isVariable($segment)) {
        //判断是否为可选变量
        $isRequire = RouteTool::isOptionalVariable($segment);
        //提取变量名称
        $segment = RouteTool::extractVariableName($segment);
        //删除结尾斜杠
        if ($isRequire) $regexPattern = rtrim($regexPattern, '/');
        // 设置规则
        $regexPattern .= $isRequire
          ? '(?:/(' . $patternRule[$segment] . '))?'
          : '(' . $patternRule[$segment] . ')';
      } else {
        // 否则，将段视为静态文本
        $regexPattern .= preg_quote($segment, '/');
      }
      //结尾添加斜杠
      $regexPattern .= '/';
    }
    // 删除最后一个斜杠
    $regexPattern = rtrim($regexPattern, '/');
    // 添加正则表达式的开始和结束标记
    return '#^/' . $regexPattern . '$#';
  }

  /**
   * 添加动态路由
   * @param string[] $urlSegments 规则数组
   * @param string $regex
   * @param string $routeIndex
   * @return void
   */
  private function addDynamicRoute(array $urlSegments, string $regex, string $routeIndex): void
  {
    $len = count($urlSegments);
    foreach ($urlSegments as $rule) {
      if (empty($rule)) continue;
      if (RouteTool::isOptionalVariable($rule)) $len--;
    }
    $path = implode('/', $urlSegments);
    if (isset($this->dynamicRoute["segment_$len"][$regex])) {
      trigger_error("{$path}路由规则已存在，重复定义即覆盖路由", E_USER_WARNING);
    }
    $this->dynamicRoute["segment_$len"][$regex] = $routeIndex;
    $fullLen = count($urlSegments);
    // 适配去掉可选参数的长度
    if ($fullLen !== $len) {
      if (isset($this->dynamicRoute['segment_' . $fullLen][$regex])) {
        trigger_error("{$path}路由规则已存在，重复定义即覆盖路由", E_USER_WARNING);
      }
      $this->dynamicRoute['segment_' . $fullLen][$regex] = $routeIndex;
    }
  }

  /**
   * 添加静态路由
   *
   * @param string $urlPath 匹配path
   * @param string $routeIndex 路由item实例索引
   * @return void
   */
  private function addStaticRoute(string $urlPath, string $routeIndex): void
  {
    if (isset($this->staticRoute[$urlPath])) {
      trigger_error(
        "{$urlPath}路由规则已存在，重复定义即覆盖路由", E_USER_WARNING
      );
    }
    $this->staticRoute[$urlPath] = $routeIndex;
  }

  /**
   * 匹配路由，返回路由实例
   *
   * @access public
   * @param string $path 路由路径
   * @param string $method 请求方式
   * @param string $domain 请求域名
   * @param array|null $params 请求参数，如果传入则会同动态路由参数合并，并传递给路由处理函数
   * @param callable|null $callback 回调函数，用于处理动态路由参数，例如将动态路由参数添加到Request对象中，该回调触发时间早于调用路由处理函数
   * @return mixed 输出结果
   */
  public function dispatch(
    string    $path,
    string    $method,
    string    $domain,
    ?array    $params = null,
    ?callable $callback = null,
  ): mixed
  {
    $PathAndExt = explode('.', $path);
    $path = $PathAndExt[0] ?? '/';
    $path = $path === '/' ? '/' : rtrim($path, '/');
    if (!$this->config->get('router.case_sensitive', false)) $path = strtolower($path);
    $ext = $PathAndExt[1] ?? '';
    $pattern = [];
    /** @var Route $route 路由 */
    $route = null;
    //判断是否存在静态路由
    if (isset($this->staticRoute[$path])) {
      $route = $this->getRoute($this->staticRoute[$path]);
    } else {
      //转换为urlPath数组
      $segments = substr_count($path, '/');
      //判断是否存在动态路由
      $routes = $this->dynamicRoute['segment_' . $segments] ?? [];
      $regexArray = array_keys($routes);
      // 遍历正则匹配路由
      foreach ($regexArray as $regex) {
        if (preg_match($regex, $path, $matches)) {
          if ($matches === null) $matches = [];
          // 如果匹配成功 则弹出默认的uri
          array_shift($matches);
          // 拿到路由
          $route = $this->getRoute($routes[$regex]);
          // 去除匹配到的key
          $keys = array_slice(array_keys($route->getPatterns()), 0, count($matches));
          // 组合为关联数组
          $pattern = array_combine($keys, $matches);
          break;
        }
      }
    }
    try {
      if (is_null($route)) throw new RouteNotFoundException('路由未定义');
      // 判断请求方法
      $this->checkOption($route->getMethod(), strtoupper($method));
      // 判断域名
      $this->checkOption($route->getDomain(), $domain);
      // 判断伪静态后缀
      $this->checkOption($route->getSuffix(), $ext);
      // 合并参数
      if (!empty($pattern)) {
        if ($callback) $callback($pattern);
        if ($params) $params = array_merge($params, $pattern);
      }
      // 绑定到容器
      bind(Route::class, $route);
      return $this->middleware->process(function () use ($route, $params) {
        return invoke($route->getHandler(), $params ?? []);
      }, $route->getMiddlewares());
    } catch (RouteNotFoundException $e) {
      // 匹配miss路由
      if (isset($this->missRoutes[$method])) {
        $miss = $this->missRoutes[$method];
      } elseif (isset($this->missRoutes['*'])) {
        $miss = $this->missRoutes['*'];
      }
      // 如果匹配到miss路由执行处理方法
      if (isset($miss)) return $miss->handler();
      // 未匹配则抛出异常
      throw $e;
    }
  }

  /**
   * 验证选项
   *
   * @param array $option
   * @param string $value
   */
  private function checkOption(
    array  $option,
    string $value
  ): void
  {
    if (
      !in_array('*', $option)
      && !in_array($value, $option)
    ) {
      throw new RouteNotFoundException('路由匹配失败');
    }
  }
}
