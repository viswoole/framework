<?php

declare(strict_types=1);

/* 职责：在路由匹配完成后补充模板与路径参数，不修改参数注入和路由分发语义。 */

namespace Viswoole\Monitor\Http;

use Throwable;
use Viswoole\Monitor\TraceContext;

/**
 * 路由观测入口
 *
 * 仅接收模板、参数与处理器等原语，避免监控模块反向依赖路由内部类型；
 * 仅请求采集启用时读取处理器标识，闭包与对象只保留类型，不持有业务对象。
 * 与 Dto\RouteSnapshot（存储侧定稿 DTO）职责不同，命名刻意区分。
 */
final class RouteCapture
{
  /**
   * 记录路由匹配结果
   *
   * @param string|null $citeLink 路由源码位置标识
   * @param array<string> $paths 声明的路由模板
   * @param array<string,mixed> $parameters 独立保存的路径参数
   * @param callable|array $handler 路由处理器
   * @return void
   */
  public static function record(
    ?string $citeLink,
    array $paths,
    array $parameters,
    callable|array $handler
  ): void
  {
    $trace = TraceContext::current();
    if ($trace === null) { return; }
    try {
      $name = is_array($handler)
        ? (is_object($handler[0]) ? $handler[0]::class : $handler[0]) . '::' . $handler[1]
        : (is_string($handler) ? $handler : get_debug_type($handler));
      $trace->updateRoute([
        'id' => $citeLink, 'paths' => $paths, 'parameters' => $parameters, 'handler' => $name,
      ]);
    } catch (Throwable $error) {
      trigger_error('慢请求路由采集失败: ' . $error::class, E_USER_WARNING);
    }
  }
}
