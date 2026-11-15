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
use Viswoole\Monitor\Http\RouteCapture;
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
  use RouteMatching;
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
    $this->routeTable = new RouteTable($config, App::factory()->make(InterfaceCatalog::class));
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
    $this->routeTable->interfaces->complete();
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
   * 获取可用于监控覆盖的精确接口目录
   * @return list<RouteIdentity> 每个具体方法及路径别名单独列项
   */
  public function getInterfaces(): array
  {
    return $this->routeTable->interfaces->getInterfaces();
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
    if (!$this->enableApiDoc) { return ['count' => 0, 'routes' => []]; }
    if (!isset($this->init)) { throw new RuntimeException('路由正在初始化，请稍后再试'); }
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
    $method = strtoupper($method);
    try {
      [$route, $pattern, $selectedSlot] = $this->matchRequest($path, $method);
      // 判断请求方法（路由已按方法维度选出，此处为防御性校验；
      // OPTIONS 预检请求跳过方法校验：预检不对应真实处理器，
      // 需放行进入中间件管道，由跨域中间件短路响应，避免预检被 404 拦截）
      if ($method !== 'OPTIONS') {
        $this->checkOption(
          $route->getMethod(),
          $method,
          "request method '$method' is not allowed"
        );
      }
      // 判断域名
      $this->checkOption($route->getDomain(), $domain, "request domain '$domain' is not allowed");
      // 伪静态后缀已在候选循环中校验（不通过时回退下一候选），无需重复校验
      // 动态路由参数先交由回调处理（如 HTTP 场景并入 Request 的 GET 参数）
      RouteCapture::matched($this->routeTable->interfaces, $selectedSlot, $method);
      RouteCapture::record($route->getCiteLink(), $route->getPaths(), $pattern, $route->getHandler());
      if (!empty($pattern) && $callback) { $callback($pattern); }
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
      RouteCapture::unmatched();
      // 匹配miss路由
      if (isset($this->missRoutes[$method])) {
        $miss = $this->missRoutes[$method];
      } elseif (isset($this->missRoutes['*'])) {
        $miss = $this->missRoutes['*'];
      }
      // 如果匹配到miss路由执行处理方法
      if (isset($miss)) { return $miss->handler(); }
      // 未匹配则抛出异常
      throw $e;
    }
  }

}
