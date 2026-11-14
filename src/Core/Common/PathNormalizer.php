<?php

declare(strict_types=1);

/* 职责：统一请求路径的逐段解码规则，供路由匹配及监控排除策略复用。 */

namespace Viswoole\Core\Common;

/**
 * 路径规范化工具
 *
 * 编码斜线不能改变路径段结构，只进行一次解码；不改变路由参数的原始大小写。
 * 放在通用工具层是为了让路由与监控等模块共享同一语义而不互相依赖。
 */
final class PathNormalizer
{
  /**
   * 解码路径中的独立片段
   *
   * @param string $path 原始请求路径
   * @return string 保留编码斜线的单次解码路径
   */
  public static function decode(string $path): string
  {
    if (!str_contains($path, '%')) { return $path; }
    $segments = explode('/', $path);
    foreach ($segments as &$segment) {
      $decoded = rawurldecode($segment);
      // %2F 不得变成路径分隔符，否则排除策略与实际路由的资源边界会改变。
      if (!str_contains($decoded, '/')) { $segment = $decoded; }
    }
    unset($segment);
    return implode('/', $segments);
  }

  /**
   * 生成排除策略的匹配路径
   *
   * 与路由一致先移除尾斜线再逐段解码；仅策略匹配按路由配置折叠大小写。
   *
   * @param string $path 原始路径或配置中的匹配模式
   * @param bool $isCaseSensitive 路由是否区分大小写
   * @return string 用于策略比较的路径
   */
  public static function forMatching(string $path, bool $isCaseSensitive): string
  {
    $normalized = self::decode($path === '/' ? '/' : rtrim($path, '/'));
    return $isCaseSensitive ? $normalized : strtolower($normalized);
  }

  /**
   * 构建路由完整路径与去后缀候选，输入必须已经完成单次解码。
   *
   * @param string $decodedPath 已解码路径
   * @return list<array{string,string}> 路径及对应后缀，完整路径优先
   */
  public static function candidates(string $decodedPath): array
  {
    $candidates = [[$decodedPath, '']];
    $dotPosition = strrpos($decodedPath, '.');
    if ($dotPosition === false) { return $candidates; }
    $extension = substr($decodedPath, $dotPosition + 1);
    $strippedPath = rtrim(substr($decodedPath, 0, $dotPosition), '/');
    // 只处理末段中的非空后缀，点号属于上级目录时不能改变资源边界。
    if ($extension !== '' && !str_contains($extension, '/') && $strippedPath !== '') {
      $candidates[] = [$strippedPath, $extension];
    }
    return $candidates;
  }
}
