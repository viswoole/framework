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
use RuntimeException;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Event;
use Viswoole\Core\FrameworkEvent;
use Viswoole\Core\Middleware;
use Viswoole\Router\ApiDoc\ApiDocParseTool;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\BaseRoute;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由器，负责路由的收集、匹配与分发，支持路由缓存与 API 文档生成
 */
class Router extends Collector
{
  /**
   * @var RouteTable 路由表（静态/动态映射与正则编译，职责见 RouteTable）
   */
  private readonly RouteTable $routeTable;
  /**
   * @var bool 是否启用路由缓存
   */
  private bool $cache;
  /**
   * @var bool 是否启用 API 文档生成
   */
  private bool $enableApiDoc;
  /**
   * @var bool 路由是否已完成初始化
   */
  private bool $init;

  /**
   * 初始化路由器，加载配置路由与注解路由并构建路由树
   *
   * @param Event $event 事件管理器，用于触发路由初始化事件
   * @param Config $config 框架配置实例
   * @param Middleware $middleware 中间件调度器
   */
  public function __construct(
    private readonly Event      $event,
    private readonly Config     $config,
    private readonly Middleware $middleware
  )
  {
    App::factory()->bind(self::class, $this);
    $this->routeTable = new RouteTable($config);
    // 是否缓存路由
    $this->cache = $config->get('router.cache.enable', false);
    // 是否生成api文档
    $this->enableApiDoc = $config->get('router.api_doc.enable', false);
    if ($this->enableApiDoc) {
      // 校验并归一化 api_doc 全局参数配置（职责见 ApiDocGlobalConfig）
      ApiDocGlobalConfig::verify($config);
    }
    // 触发路由初始化事件，其他模块可以监听该事件注册路由
    $this->event->emit(FrameworkEvent::RouterInitializing);
    // 装载配置路由与注解路由（职责见 RouteLoader）
    RouteLoader::load($this, $config, $this->cache);
    $this->parseRoute();
    $this->init = true;
    $this->event->emit(FrameworkEvent::RouterInitialized);
  }

  /**
   * 解析路由(最后执行)
   *
   * @return void
   */
  private function parseRoute(): void
  {
    // 对路由进行排序
    uasort($this->routes, function (BaseRoute $a, BaseRoute $b) {
      return $b->getSort() <=> $a->getSort();
    });
    foreach ($this->routes as $key => $item) {
      if ($parent = $item->getParentId()) {
        // 处理自定义父级路由依赖：父级必须是分组路由，指向路由项时若静默降级
        // 会让路由脱离预期分组且难以排查，注册期直接给出明确错误
        $routeGroup = $this->getRoute($parent);
        if (!$routeGroup instanceof Group) {
          throw new InvalidArgumentException(
            "路由 parentId 引用错误（{$parent}）：父级必须是分组路由或控制器注解路由"
          );
        }
        $routeGroup->addItem($item);
        unset($this->routes[$key]);
        continue;
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
        $this->routeTable->insert($path, $route->getCiteLink(), $route);
      }
    }
  }

  /**
   * 获取路由文档
   *
   * @return array{count: int,routes: array} 路由数量和路由列表
   * @throws RuntimeException 路由正在初始化
   * @see ApiDocParseTool::generateGroup() 分组结构
   * @see ApiDocParseTool::generateRoute() 路由结构
   */
  public function getApiList(): array
  {
    if (!$this->enableApiDoc) return ['count' => 0, 'routes' => []];
    if (!isset($this->init)) throw new RuntimeException('路由正在初始化，请稍后再试');
    return ApiDocParseTool::parse($this->getRoutes());
  }

  /**
   * 获取API文档的详情，包含请求参数，接口返回值
   *
   * @param string $citeLink 路由线路的引用链接
   * @return array
   * @throws InvalidArgumentException 路由不存在
   * @see Route::getReturned() 响应组件结构详情
   * @see Route::getParams() 参数结构详情
   */
  public function getApiDetail(string $citeLink): array
  {
    $route = $this->getRoute($citeLink);
    return [
      'params' => $route->getParams(),
      'returned' => $route->getReturned()
    ];
  }

  /**
   * 判断是否启用了 API 文档解析功能
   *
   * @return bool
   */
  public function isEnableApiDoc(): bool
  {
    return $this->enableApiDoc;
  }

  /**
   * 匹配路由，返回路由实例
   *
   * 支持同一路径按不同请求方法注册多条路由（如 GET /profile 与 PUT /profile），
   * 匹配时先按路径定位再按请求方法选择路由；路径命中但方法均不匹配时
   * 抛出方法不允许异常（与 404 同样会回退 miss 路由）。
   *
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
    $path = $path === '/' ? '/' : rtrim($path, '/');
    // 请求路径以百分号编码到达（如空格 %20、中文），逐段解码后才能与注册路径
    // 及参数约束匹配；逐段处理避免 %2F 解码出新的路径分隔符破坏段结构
    // （注册侧 handlePaths 对静态段同步解码，两侧对齐）
    $decodedPath = $this->decodeRequestPath($path);
    $caseSensitive = $this->config->get('router.case_sensitive', false);
    // 请求方法统一规范化为大写（方法映射表与 miss 路由表均以大写为键）
    $method = strtoupper($method);
    // 候选路径：完整路径优先（点号属于动态参数值，如 /user/john.doe），
    // 命中路由但伪静态后缀校验失败时再尝试剥离后缀的路径（仅按最后一个
    // 不含 / 的点段剥离）。无点号时仅一个候选，保持原语义
    $candidates = [[$decodedPath, '']];
    $dotPos = strrpos($decodedPath, '.');
    if ($dotPos !== false) {
      $ext = substr($decodedPath, $dotPos + 1);
      if ($ext !== '' && !str_contains($ext, '/')) {
        $strippedPath = rtrim(substr($decodedPath, 0, $dotPos), '/');
        if ($strippedPath !== '') $candidates[] = [$strippedPath, $ext];
      }
    }
    $pattern = [];
    /** @var Route|null $route 路由 */
    $route = null;
    // 路径已命中但请求方法均不匹配时为 true，用于区分 404 与方法不允许两种未命中语义
    $pathMatched = false;
    // 路由命中但伪静态后缀校验未通过时暂存的异常，所有候选穷尽后抛出（回退 miss 路由）
    $suffixError = null;
    foreach ($candidates as [$candPath, $ext]) {
      // 静态路由查表用小写键（注册侧静态段已小写）；动态正则匹配用原始大小写路径，
      // 正则静态段以内联 (?i:) 分组实现大小写不敏感，动态参数值保留原始大小写
      $matchPath = $caseSensitive ? $candPath : strtolower($candPath);
      // 判断是否存在静态路由
      $methodMap = $this->routeTable->matchStatic($matchPath);
      if ($methodMap !== null) {
        $routeIndex = $this->selectRouteIndex($methodMap, $method);
        if ($routeIndex !== null) {
          $route = $this->getRoute($routeIndex);
        } else {
          $pathMatched = true;
        }
      } else {
        // 按段数定位动态路由分组
        $routes = $this->routeTable->dynamicBucket(substr_count($candPath, '/'));
        // 遍历正则匹配路由（使用原始大小写路径，保留动态参数值大小写）
        foreach (array_keys($routes) as $regex) {
          if (!preg_match($regex, $candPath, $matches)) continue;
          $routeIndex = $this->selectRouteIndex($routes[$regex], $method);
          if ($routeIndex === null) {
            // 路径命中但该正则下无匹配请求方法的路由，
            // 继续尝试后续正则（可能存在另一模式支持该方法的路由）
            $pathMatched = true;
            continue;
          }
          // 拿到路由
          $route = $this->getRoute($routeIndex);
          // 命名捕获组直接给出 变量名 => 值：与路由 patterns 键求交集，
          // 数字键（PCRE 自动编号）与约束正则内部自定义的命名组均被过滤，
          // 仅保留路径声明的变量；未匹配的可选变量不出现在结果中，
          // 由参数注入层用方法默认值兜底。不再依赖 patterns 键序与捕获组
          // 顺序对齐（旧实现遇约束正则含捕获组时 array_combine 会崩溃）
          $pattern = array_intersect_key($matches, $route->getPatterns());
          break;
        }
      }
      // 未命中任何路由则尝试下一候选
      if ($route === null) continue;
      // 伪静态后缀校验不通过时不抛异常，回退下一候选：
      // 完整路径候选可能把后缀误当作参数值的一部分（如 /article/5.html 与 {id}）
      if (!in_array('*', $route->getSuffix()) && !in_array($ext, $route->getSuffix())) {
        $suffixError = new RouteNotFoundException("request suffix '$ext' is not allowed");
        $route = null;
        $pattern = [];
        continue;
      }
      break;
    }
    try {
      if (is_null($route)) {
        if ($suffixError !== null) throw $suffixError;
        throw new RouteNotFoundException(
          $pathMatched
            ? "request method '$method' is not allowed"
            : 'routing resource not found'
        );
      }
      // 判断请求方法（路由已按方法维度选出，此处为防御性校验；
      // OPTIONS 预检请求跳过方法校验：预检不对应真实处理器，
      // 需放行进入中间件管道，由跨域中间件短路响应，避免预检被 404 拦截）
      if ($method !== 'OPTIONS') {
        $this->checkOption(
          $route->getMethod(), $method, "request method '$method' is not allowed"
        );
      }
      // 判断域名
      $this->checkOption($route->getDomain(), $domain, "request domain '$domain' is not allowed");
      // 伪静态后缀已在候选循环中校验（不通过时回退下一候选），无需重复校验
      // 动态路由参数先交由回调处理（如 HTTP 场景并入 Request 的 GET 参数）
      if (!empty($pattern) && $callback) $callback($pattern);
      // 修复#8: $params 必须先初始化再合并，否则未显式传参时（如 HTTP 请求）动态路由
      // 参数无法并入注入参数，方法参数就不能按名隐式获取路由参数（/user/1024 → $id = 1024）。
      // 合并语义保持不变：路由匹配参数优先于手动传入的同名参数；GET/POST 请求参数仍需注解显式注入
      $params = array_merge($params ?? [], $pattern);
      // 绑定到容器
      bind(Route::class, $route);
      return $this->middleware->process(function () use ($route, $params) {
        return invoke($route->getHandler(), $params);
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
   * 对请求路径逐段进行 URL 解码
   *
   * 请求路径以百分号编码形式到达（如空格 %20、中文），解码后才能与注册的
   * 路由路径及参数约束匹配（注册侧 handlePaths 对静态段同步解码）。
   * 按 / 分段后逐段解码；解码后包含 / 的段（如 %2F）保留编码原样，
   * 防止编码斜杠被还原为真实路径分隔符导致路径结构被重写
   * （如 /user/a%2Fb 被改写为 /user/a/b 而匹配到其他路由）。
   *
   * @param string $path 原始请求路径
   * @return string 解码后的路径
   */
  private function decodeRequestPath(string $path): string
  {
    // 无编码字符时直接返回，免去拆分开销
    if (!str_contains($path, '%')) return $path;
    $segments = explode('/', $path);
    foreach ($segments as &$segment) {
      if ($segment === '') continue;
      $decoded = rawurldecode($segment);
      // 解码引入新分隔符的段保持编码原样（注册侧 handlePaths 同规则，两侧对齐）
      if (!str_contains($decoded, '/')) $segment = $decoded;
    }
    unset($segment);
    return implode('/', $segments);
  }

  /**
   * 从方法映射表中选择匹配请求方法的路由引用链路
   *
   * 选择优先级：精确方法匹配 > '*' 通配 > （仅 OPTIONS）该路径下任意路由。
   * OPTIONS 预检请求不对应真实处理器，回退到任意路由以放行进入中间件管道，
   * 由跨域中间件短路响应。
   *
   * @param array<string,string> $methodMap [请求方法 => 路由引用链路] 映射表
   * @param string $method 规范化后的请求方法（大写）
   * @return string|null 路由引用链路，无匹配时返回 null
   */
  private function selectRouteIndex(array $methodMap, string $method): ?string
  {
    if (isset($methodMap[$method])) return $methodMap[$method];
    if (isset($methodMap['*'])) return $methodMap['*'];
    if ($method === 'OPTIONS') {
      // reset() 在空数组上返回 false，需显式判空
      return $methodMap === [] ? null : reset($methodMap);
    }
    return null;
  }

  /**
   * 校验请求选项（方法/域名/后缀）是否在允许列表中
   *
   * 允许列表中包含 '*' 时视为不限制。
   *
   * @param array $option 允许的值列表
   * @param string $value 实际请求值
   * @param string $message 不匹配时的异常消息
   * @throws RouteNotFoundException 不匹配时抛出
   */
  private function checkOption(
    array  $option,
    string $value,
    string $message
  ): void
  {
    if (
      !in_array('*', $option)
      && !in_array($value, $option)
    ) {
      throw new RouteNotFoundException($message);
    }
  }
}
