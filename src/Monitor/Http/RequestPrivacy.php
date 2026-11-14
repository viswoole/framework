<?php

declare(strict_types=1);

namespace Viswoole\Monitor\Http;

use Viswoole\Monitor\PayloadSanitizer;

/** 职责：清理请求展示信息中的凭据副本，不参与真实路由匹配或修改请求对象。 */
final class RequestPrivacy
{
  /**
   * 清理标头中的 URL，通用字段脱敏仍由调用方完成
   * @param array<array-key,mixed> $headers 请求头
   * @return array<array-key,mixed> URL 已移除查询串、用户信息和锚点的副本
   */
  public static function sanitizeHeaders(array $headers): array
  {
    foreach ($headers as $key => $value) {
      if (strtolower((string)$key) === 'referer' && is_string($value)) {
        $headers[$key] = UriSanitizer::sanitize($value);
      }
    }
    return $headers;
  }

  /**
   * 隐藏携带敏感路由参数的展示路径
   * @param array<string,mixed> $request 已采集请求
   * @param array<string,mixed> $parameters 匹配所得原始参数
   * @param PayloadSanitizer $sanitizer 当前路径的有效脱敏策略
   * @return array<string,mixed> 安全展示数据
   */
  public static function redactPath(array $request, array $parameters, PayloadSanitizer $sanitizer): array
  {
    foreach (array_keys($parameters) as $key) {
      if ($sanitizer->isSensitiveField((string)$key)) {
        // 整体隐藏避免编码变体、跨段参数和重复值导致局部替换漏掉凭据。
        $request['path'] = '[REDACTED]';
        break;
      }
    }
    return $request;
  }
}
