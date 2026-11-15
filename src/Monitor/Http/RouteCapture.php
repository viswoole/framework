<?php

declare(strict_types=1);

/* 职责：观察路由成功、失败及预检结果，固定采集策略，不改变路由分发语义。 */

namespace Viswoole\Monitor\Http;

use Throwable;
use Viswoole\Monitor\Runtime\ObservationFailure;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\InterfaceCatalog;

/**
 * 路由观测入口
 *
 * 从路由候选目录取得不可变接口身份，不持有路由或处理器对象；
 * 仅请求采集启用时读取处理器标识，闭包与对象只保留类型，不持有业务对象。
 * 与 Dto\RouteSnapshot（存储侧定稿 DTO）职责不同，命名刻意区分。
 */
final class RouteCapture
{
  /**
   * 从真实命中槽位固定策略，身份构造失败只影响观测
   * @param InterfaceCatalog $catalog 路由候选元数据
   * @param string|null $slot 已匹配槽位
   * @param string $method 具体方法
   * @return void
   */
  public static function matched(InterfaceCatalog $catalog, ?string $slot, string $method): void
  {
    if ($slot === null || TraceContext::current() === null) { return; }
    try {
      $identity = $catalog->selected($slot, $method);
      if ($identity !== null) { TraceContext::current()?->freezeCapturePolicy($identity); }
      else { self::unmatched(); }
    } catch (Throwable $error) {
      // 外层身份不可用时仍须固定入口策略，防止内部分发接管整个请求。
      self::unmatched();
      ObservationFailure::report($error);
    }
  }

  /**
   * 路由失败时在 miss 处理器之前固定入口全局策略
   * @return void
   */
  public static function unmatched(): void
  {
    try { TraceContext::current()?->freezeGlobalPolicy(); }
    catch (Throwable $error) { ObservationFailure::report($error); }
  }

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
