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
use Viswoole\Core\Config;
use Viswoole\Router\Route\BaseRoute;

/**
 * 路由表
 *
 * 维护静态路由映射与动态路由树两类存储，负责路由插入时的正则编译：
 * - 静态表：[路径 => [请求方法 => 路由引用链路]]，同一路径允许不同请求方法共存
 * - 动态树：按路径段数分组（segment_N），[正则 => [请求方法 => 路由引用链路]]
 * 仅当同一路径且同一请求方法重复定义时告警并覆盖
 *
 * dispatch 侧通过 matchStatic()/dynamicBucket() 查表，方法选择与分发
 * 逻辑保留在 Router
 */
final class RouteTable
{
  /**
   * @var array<string,array<string,string>> 静态路由映射
   *      键为 URL 路径，值为 [请求方法 => 路由引用链路]
   */
  private array $staticRoute = [];
  /**
   * @var array<string,array<string,array<string,string>>> 动态路由映射
   *      按路径段数分组（segment_N），第一层键为正则，值为 [请求方法 => 路由引用链路]
   */
  private array $dynamicRoute = [];

  /**
   * @param Config $config 框架配置实例（读取 router.case_sensitive 控制正则大小写语义）
   */
  public function __construct(private readonly Config $config)
  {
  }

  /**
   * 将路由插入静态路由表或动态路由树
   *
   * @param string $path 路由路径
   * @param string $routeIndex 路由引用链路
   * @param BaseRoute $route 路由实例（提供 patterns 与请求方法）
   * @throws InvalidArgumentException 动态路由正则编译失败时抛出
   */
  public function insert(string $path, string $routeIndex, BaseRoute $route): void
  {
    if (RouterTool::isVariable($path)) {
      $urlSegments = explode('/', $path);
      $urlSegments = array_filter($urlSegments, function ($value) {
        return $value !== '';
      });
      $regex = $this->convertRegex($urlSegments, $route->getPatterns());
      $this->addDynamicRoute($urlSegments, $regex, $routeIndex, $route->getMethod());
    } else {
      $this->addStaticRoute($path, $routeIndex, $route->getMethod());
    }
  }

  /**
   * 查询静态路由表
   *
   * @param string $path 归一化后的请求路径
   * @return array<string,string>|null [请求方法 => 路由引用链路]，路径未命中时返回 null
   */
  public function matchStatic(string $path): ?array
  {
    return $this->staticRoute[$path] ?? null;
  }

  /**
   * 查询指定段数的动态路由分组
   *
   * @param int $segmentCount 请求路径段数
   * @return array<string,array<string,string>> [正则 => [请求方法 => 路由引用链路]]
   */
  public function dynamicBucket(int $segmentCount): array
  {
    return $this->dynamicRoute['segment_' . $segmentCount] ?? [];
  }

  /**
   * 将 URL 路径段和参数正则约束转换为完整匹配正则
   *
   * @param string[] $segments URL 路径段数组
   * @param array $patternRule 参数名到正则约束的映射
   * @return string 完整的匹配正则表达式
   * @throws InvalidArgumentException 正则无法编译时抛出
   */
  private function convertRegex(array $segments, array $patternRule = []): string
  {
    // 大小写不敏感路由（case_sensitive=false）下，静态段以内联 (?i:) 分组包裹：
    // 匹配对请求路径的原始大小写生效，而动态参数约束正则保持自身大小写语义；
    // dispatch 侧因此使用原始路径匹配，避免整体小写导致参数值丢失大小写
    $caseSensitive = $this->config->get('router.case_sensitive', false);
    $regexPattern = '';
    foreach ($segments as $segment) {
      // 判断是否为变量字段
      if (RouterTool::isVariable($segment)) {
        // 判断是否为可选变量
        $isRequire = RouterTool::isOptionalVariable($segment);
        // 提取变量名称
        $segment = RouterTool::extractVariableName($segment);
        // 删除结尾斜杠
        if ($isRequire) $regexPattern = rtrim($regexPattern, '/');
        // 设置规则：使用命名捕获组，dispatch 直接按组名提取参数，
        // 消除"patterns 键序与捕获组顺序对齐"的脆弱假设；
        // 约束正则中的匿名捕获组也不会再混入参数表
        $regexPattern .= $isRequire
          ? '(?:/(?P<' . $segment . '>' . $patternRule[$segment] . '))?'
          : '(?P<' . $segment . '>' . $patternRule[$segment] . ')';
      } else {
        // 否则，将段视为静态文本（大小写不敏感路由时包裹内联忽略大小写分组）
        $static = preg_quote($segment, '/');
        $regexPattern .= $caseSensitive ? $static : "(?i:$static)";
      }
      //结尾添加斜杠
      $regexPattern .= '/';
    }
    // 删除最后一个斜杠
    $regexPattern = rtrim($regexPattern, '/');
    // 添加正则表达式的开始和结束标记
    $regex = '#^/' . $regexPattern . '$#';
    // 编译期校验：重复变量名、非法组名等问题会让正则无法编译，
    // 不在此处拦截将表现为路由静默永不匹配，极难排查
    if (@preg_match($regex, '') === false) {
      throw new InvalidArgumentException(
        '路由正则编译失败（' . implode('/', $segments) . '）: ' . preg_last_error_msg()
      );
    }
    return $regex;
  }

  /**
   * 添加动态路由
   *
   * 按 [正则 => [请求方法 => 路由引用链路]] 存储，同一正则允许不同请求方法共存，
   * 仅当同一路径且同一请求方法重复定义时告警并覆盖。
   *
   * @param string[] $urlSegments URL 路径段数组
   * @param string $regex 匹配正则
   * @param string $routeIndex 路由引用链路
   * @param string[] $methods 路由允许的请求方式列表
   */
  private function addDynamicRoute(
    array  $urlSegments,
    string $regex,
    string $routeIndex,
    array  $methods,
  ): void
  {
    $len = count($urlSegments);
    foreach ($urlSegments as $rule) {
      if (empty($rule)) continue;
      if (RouterTool::isOptionalVariable($rule)) $len--;
    }
    $path = implode('/', $urlSegments);
    // 初始化方法映射表，避免引用传参时未定义维度自动置为 null 触发类型错误
    if (!isset($this->dynamicRoute["segment_$len"][$regex])) {
      $this->dynamicRoute["segment_$len"][$regex] = [];
    }
    $this->registerMethodRoute(
      $this->dynamicRoute["segment_$len"][$regex], $path, $methods, $routeIndex
    );
    $fullLen = count($urlSegments);
    // 适配去掉可选参数的长度
    if ($fullLen !== $len) {
      if (!isset($this->dynamicRoute['segment_' . $fullLen][$regex])) {
        $this->dynamicRoute['segment_' . $fullLen][$regex] = [];
      }
      $this->registerMethodRoute(
        $this->dynamicRoute['segment_' . $fullLen][$regex], $path, $methods, $routeIndex
      );
    }
  }

  /**
   * 将路由按请求方式写入方法映射表（引用方式写入，供静态/动态路由表复用）
   *
   * 包含 '*' 时视为不限制请求方式，仅记录 '*' 键（分发时作为通配回退），
   * 避免 '*' 与具体方法同时注册导致的通配优先级歧义。
   *
   * @param array<string,string> $methodMap 方法映射表（按引用写入）
   * @param string $path 用于告警提示的路径
   * @param string[] $methods 路由允许的请求方式列表
   * @param string $routeIndex 路由引用链路
   */
  private function registerMethodRoute(
    array  &$methodMap,
    string $path,
    array  $methods,
    string $routeIndex
  ): void
  {
    if (in_array('*', $methods)) $methods = ['*'];
    foreach ($methods as $method) {
      if (isset($methodMap[$method])) {
        trigger_error(
          "{$path}路由规则已存在（请求方法：{$method}），重复定义即覆盖路由", E_USER_WARNING
        );
      }
      $methodMap[$method] = $routeIndex;
    }
  }

  /**
   * 注册静态路由到映射表
   *
   * 按 [路径 => [请求方法 => 路由引用链路]] 存储，同一路径允许不同请求方法共存，
   * 仅当同一路径且同一请求方法重复定义时告警并覆盖。
   *
   * @param string $urlPath 完整 URL 路径
   * @param string $routeIndex 路由引用链路
   * @param string[] $methods 路由允许的请求方式列表
   */
  private function addStaticRoute(string $urlPath, string $routeIndex, array $methods): void
  {
    // 初始化方法映射表，避免引用传参时未定义维度自动置为 null 触发类型错误
    if (!isset($this->staticRoute[$urlPath])) $this->staticRoute[$urlPath] = [];
    $this->registerMethodRoute($this->staticRoute[$urlPath], $urlPath, $methods, $routeIndex);
  }
}
