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

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Viswoole\Core\Config;
use Viswoole\Core\Middleware;
use Viswoole\Router\Exception\RouteNotFoundException;

/**
 * 路由收集器
 */
class RouteCollector
{
  /**
   * @var RouteGroup|null 当前组路由实例
   */
  public null|RouteGroup $currentGroup = null;
  /**
   * @var array<string,string> 完全静态的路由item索引
   */
  protected array $staticRoute = [];
  /**
   * @var array<string,string> 动态参数的子节点
   */
  protected array $dynamicRoute = [];
  /**
   * @var RouteItem[]|RouteGroup[] 路由列表
   */
  protected array $routes = [];
  /**
   * @var array{string:RouteMiss} miss路由404
   */
  protected array $missRoutes = [];

  /**
   * @param Config $config
   * @param Middleware $middleware
   */
  public function __construct(
    private readonly Config     $config,
    private readonly Middleware $middleware
  )
  {
  }

  /**
   * 分组路由
   *
   * @access public
   * @param string|array $prefix 前缀
   * @param Closure $closure 闭包
   * @param string $id 非注解路由，系统无法生成唯一且不变的id，需手动指定id
   * @return RouteGroup
   */
  public function group(string|array $prefix, Closure $closure, string $id): RouteGroup
  {
    $route = new RouteGroup($prefix, $closure, $this->currentGroup?->getOptions(), id: $id);
    // 判断是否存在路由分组，如果存在则添加到当前分组
    if ($this->currentGroup === null) {
      $this->routes[] = $route;
    } else {
      $this->currentGroup->addItem($route);
    }
    return $route;
  }

  /**
   * miss路由（在未匹配到路由的时候输出）
   * @access public
   * @param Closure $handler
   * @param string|string[] $method
   * @return void
   */
  public function miss(
    Closure      $handler,
    string|array $method = '*'
  ): void
  {
    if (!is_array($method)) $method = [$method];
    foreach ($method as $item) {
      $this->missRoutes[$item] = new RouteMiss($handler);
    }
  }

  /**
   * 匹配快捷方法
   * @param string $name
   * @param array $arguments
   * @return RouteConfig
   */
  public function __call(string $name, array $arguments)
  {
    $method = ['post', 'get', 'put', 'delete', 'patch', 'head', 'any', 'add'];
    if (in_array($name, $method)) {
      if ($name !== 'add') {
        $name = $name === 'any' ? '*' : strtoupper($name);
        $arguments[] = $name;
      }
      return $this->addRoute(...$arguments);
    } else {
      $met = __CLASS__ . "::$name";
      throw new BadMethodCallException("RouteItem not found static method $met");
    }
  }

  /**
   * 自定义路由
   *
   * @param string|array $paths 匹配规则，动态规则示例：user/{id}|user/{id?}
   * @param string|array|callable|Closure $handler 路由地址
   * @param string|string[] $method 请求类型可传数组定义多个
   * @return RouteConfig
   */
  public function addRoute(
    string|array                  $paths,
    string|callable|array|Closure $handler,
    null|string|array             $method,
  ): RouteConfig
  {
    $route = new RouteItem(
      $paths,
      $handler,
      $this->currentGroup?->getOptions(),
    );
    if (!empty($method)) $route->method($method);
    $this->recordRouteItem($route);
    return $route;
  }

  /**
   * 记录路由
   *
   * @param RouteConfig $route
   * @return void
   */
  public function recordRouteItem(RouteConfig $route): void
  {
    if ($this->currentGroup === null) {
      $id = $route['id'];
      if (isset($this->routes[$id])) {
        $path = implode('|', $route['paths']);
        throw new RuntimeException("Route id:$id already exists,path:$path");
      }
      $this->routes[$id] = $route;
    } else {
      $this->currentGroup->addItem($route);
    }
  }

  /**
   * 注册路由
   *
   * @access public
   * @param RouteItem $route
   * @return void
   */
  public function registerRouteItem(RouteItem $route): void
  {
    foreach ($route['paths'] as $path) {
      $this->insertRoute($path, $route->getCiteLink());
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
    if (self::isVariable($path)) {
      $urlSegments = explode('/', $path);
      $urlSegments = array_filter($urlSegments, function ($value) {
        return $value !== '';
      });
      $regex = $this->convertRegex(
        $urlSegments,
        $this->getRoute($routeIndex)['pattern']
      );
      $this->addDynamicRoute($urlSegments, $regex, $routeIndex);
    } else {
      $this->addStaticRoute($path, $routeIndex);
    }
  }

  /**
   * 判断字符串中是否包含{}包裹的变量
   *
   * @param string $str
   * @return bool
   */
  public static function isVariable(string $str): bool
  {
    return preg_match('/\{[^}]+\??}/', $str) === 1;
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
      if (self::isVariable($segment)) {
        //判断是否为可选变量
        $isRequire = self::isOptionalVariable($segment);
        //提取变量名称
        $segment = self::extractVariableName($segment);
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
   * 判断是否为可选变量
   * @param string $str
   * @return bool
   */
  public static function isOptionalVariable(string $str): bool
  {
    return preg_match('/^\{[^}]+\?}$/', $str) === 1;
  }

  /**
   * 提取变量名称
   * @param string $routePattern
   * @return string
   */
  public static function extractVariableName(string $routePattern): string
  {
    return str_replace(['{', '}', '?', ' '], '', $routePattern);
  }

  /**
   * 获取路由对象
   *
   * @param string $idOrCiteLink
   * @return RouteConfig
   * @throws InvalidArgumentException
   */
  private function getRoute(string $idOrCiteLink): RouteConfig
  {
    if (str_contains($idOrCiteLink, '.')) {
      $citeLink = explode('.', $idOrCiteLink);
      $key = $citeLink[0];
      $route = $this->routes[$key]
        ?? throw new InvalidArgumentException("路由链路引用错误{$idOrCiteLink}： $key");
      for ($i = 1; $i < count($citeLink); $i++) {
        $key = $citeLink[$i];
        if (!$route) throw new InvalidArgumentException("路由链路引用错误{$idOrCiteLink}： $key");
        if (!$route instanceof RouteGroup) throw new InvalidArgumentException(
          "路由链路引用错误{$idOrCiteLink}： {$key}必须是组路由|控制器注解路由"
        );
        $route = $route->getItem($key);
      }
      return $route;
    } else {
      return $this->routes[$idOrCiteLink]
        ?? throw new InvalidArgumentException("路由不存在：$idOrCiteLink");
    }
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
      if (self::isOptionalVariable($rule)) $len--;
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
   * 解析路由(最后执行)
   *
   * @access public
   * @return void
   */
  public function parseRoute(): void
  {
    // 对路由进行排序
    uasort($this->routes, function (RouteConfig $a, RouteConfig $b) {
      return $b->sort <=> $a->sort;
    });
    foreach ($this->routes as $key => $item) {
      if ($parent = $item->getCiteLink(false)) {
        // 处理自定义父级路由依赖
        $routeGroup = $this->getRoute($parent);
        if ($routeGroup instanceof RouteGroup) {
          $routeGroup->addItem($item);
          unset($this->routes[$key]);
          continue;
        }
      }
      $item->_register($this);
    }
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
    /** @var RouteConfig $route 路由 */
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
          $keys = array_slice(array_keys($route['pattern']), 0, count($matches));
          // 组合为关联数组
          $pattern = array_combine($keys, $matches);
          break;
        }
      }
    }
    try {
      if (is_null($route)) throw new RouteNotFoundException('路由未定义');
      // 判断请求方法
      $this->checkOption($route, 'method', $method);
      // 判断域名
      $this->checkOption($route, 'domain', $domain);
      // 判断伪静态后缀
      $this->checkOption($route, 'suffix', $ext);
      // 合并参数
      if (!empty($pattern)) {
        if ($callback) $callback($pattern);
        if ($params) $params = array_merge($params, $pattern);
      }
      return $this->middleware->process(function () use ($route, $params) {
        return invoke($route['handler'], $params ?? []);
      }, $route['middleware']);
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
   * @param RouteConfig|RouteItem $route
   * @param string $option_name
   * @param string $value
   */
  private function checkOption(
    RouteConfig|RouteItem $route,
    string                $option_name,
    string                $value
  ): void
  {
    if (
      !in_array('*', $route[$option_name] ?? [])
      && !in_array($value, $route[$option_name] ?? [])
    ) {
      throw new RouteNotFoundException('路由匹配失败');
    }
  }
}
