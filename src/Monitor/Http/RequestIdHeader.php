<?php

declare(strict_types=1);

/* 职责：将当前请求 ID 写入配置的响应头；与慢请求阈值及正文采集开关无关。 */

namespace Viswoole\Monitor\Http;

use Swoole\Http\Response;
use Throwable;
use Viswoole\Monitor\TraceContext;

/**
 * 请求关联响应头
 *
 * 在 HTTP 入口仅设置一次，避免 SSE 分段发送后反复设置标头；失败仅报告异常类型。
 */
final class RequestIdHeader
{
  /**
   * 写入关联标头
   *
   * @param Response $response 原生响应对象
   * @return void
   */
  public static function apply(Response $response): void
  {
    $trace = TraceContext::current();
    $header = $trace?->options->requestId->responseHeader;
    if ($header === null) { return; }
    try {
      if (!$response->header($header, $trace->requestId)) {
        trigger_error('请求 ID 响应头写入失败', E_USER_WARNING);
        return;
      }
      $trace->updateResponseHeader($header, $trace->requestId);
    } catch (Throwable $error) {
      trigger_error('请求 ID 响应头写入失败: ' . $error::class, E_USER_WARNING);
    }
  }
}
