<?php

declare(strict_types=1);

/* 职责：选择路由表中的实际候选及其模板槽位，不执行处理器或重新解释身份。 */

namespace Viswoole\Router;

use Viswoole\Core\Common\PathNormalizer;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\Route;

/** 仅由 Router 使用，静态优先、方法回退与伪静态语义保持一致。 */
trait RouteMatching
{
  /**
   * 完成路径候选与后缀校验
   * @param string $path 原始请求路径
   * @param string $method 大写具体方法
   * @return array{Route,array<string,string>,string} 路由、参数及实际槽位
   * @throws RouteNotFoundException 无可用路由
   */
  private function matchRequest(string $path, string $method): array
  {
    $decoded = $this->decodeRequestPath($path === '/' ? '/' : rtrim($path, '/'));
    $hasPathMatch = false;
    $suffixError = null;
    foreach (PathNormalizer::candidates($decoded) as [$candidate, $extension]) {
      [$route, $parameters, $slot, $hasCandidateMatch] = $this->matchCandidate($candidate, $method);
      $hasPathMatch = $hasPathMatch || $hasCandidateMatch;
      if ($route === null) { continue; }
      // 完整路径可把后缀吃进变量，失败后仍需尝试剥离后缀候选。
      if (!in_array('*', $route->getSuffix()) && !in_array($extension, $route->getSuffix())) {
        $suffixError = new RouteNotFoundException("request suffix '$extension' is not allowed");
        continue;
      }
      return [$route, $parameters, $slot];
    }
    throw $suffixError ?? new RouteNotFoundException($hasPathMatch
      ? "request method '$method' is not allowed" : 'routing resource not found');
  }

  /**
   * 优先选择静态槽位，否则匹配动态候选
   * @param string $path 已解码且保留参数大小写的路径
   * @param string $method 具体方法
   * @return array{?Route,array<string,string>,?string,bool} 路由、参数、槽位及路径是否存在
   */
  private function matchCandidate(string $path, string $method): array
  {
    $matchPath = $this->config->get('router.case_sensitive', false) ? $path : strtolower($path);
    $methods = $this->routeTable->matchStatic($matchPath);
    if ($methods === null) { return $this->matchDynamic($path, $method); }
    $index = $this->selectRouteIndex($methods, $method);
    return $index === null ? [null, [], null, true]
      : [$this->getRoute($index), [], 'static:' . $matchPath, true];
  }

  /**
   * 从真实正则候选中保留命中槽位
   * @param string $path 已解码路径
   * @param string $method 具体方法
   * @return array{?Route,array<string,string>,?string,bool} 匹配结果
   */
  private function matchDynamic(string $path, string $method): array
  {
    $routes = $this->routeTable->dynamicBucket(substr_count($path, '/'));
    // 跨段参数正则可能命中其他段数桶；当前桶优先，不能改变原分发顺序。
    foreach ($this->routeTable->dynamicBucketsAll() as $bucket) {
      foreach ($bucket as $regex => $methods) { $routes[$regex] ??= $methods; }
    }
    $hasPathMatch = false;
    foreach ($routes as $regex => $methods) {
      if (!preg_match($regex, $path, $matches)) { continue; }
      $hasPathMatch = true;
      $index = $this->selectRouteIndex($methods, $method);
      if ($index === null) { continue; }
      $route = $this->getRoute($index);
      // 只提取声明参数，过滤 PCRE 数字键及约束内部额外的捕获组。
      return [$route, array_intersect_key($matches, $route->getPatterns()), 'dynamic:' . $regex, true];
    }
    return [null, [], null, $hasPathMatch];
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
    return PathNormalizer::decode($path);
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
    if (isset($methodMap[$method])) { return $methodMap[$method]; }
    if (isset($methodMap['*'])) { return $methodMap['*']; }
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
