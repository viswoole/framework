<?php

declare(strict_types=1);

namespace Viswoole\Monitor\Http;

/** 日志 URL 清理边界；不修改实际跳转地址，不保留查询串、锚点或用户信息。 */
final class UriSanitizer
{
  /**
   * 提取可记录的地址；无法可靠解析时隐藏整个值。
   * @param string $uri 原始 Location 或跳转地址
   * @return string 仅包含协议、主机、端口及路径的地址
   */
  public static function sanitize(string $uri): string
  {
    $parts = parse_url($uri);
    // 畸形地址无法安全区分 userinfo 与 path，不能回退为保留原始前缀。
    if ($parts === false) { return '[INVALID_URI]'; }
    $prefix = '';
    if (isset($parts['host'])) {
      $prefix = (isset($parts['scheme']) ? $parts['scheme'] . ':' : '') . '//'
        . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    } elseif (isset($parts['scheme'])) {
      // 不透明 URI 的 path 可能本身就是凭据，只保留协议供诊断。
      return $parts['scheme'] . ':[OMITTED]';
    }
    return $prefix . ($parts['path'] ?? '');
  }
}
