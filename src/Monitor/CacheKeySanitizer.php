<?php

declare(strict_types=1);

/* 职责：将可能携带会话凭据的缓存键及标签转换为可关联的稳定摘要。 */

namespace Viswoole\Monitor;

/**
 * 缓存标识脱敏
 *
 * 不依赖键内容命中敏感词；批量键只保留有限条摘要，不读取缓存值。
 */
final class CacheKeySanitizer
{
  /**
   * 隐藏缓存元数据中的标识原值
   *
   * @param array<string,mixed> $metadata 操作元数据
   * @return array<string,mixed> 已处理的缓存标识
   */
  public static function sanitize(array $metadata): array
  {
    if (($metadata['type'] ?? '') !== 'cache') { return $metadata; }
    foreach (['key', 'tags'] as $field) {
      if (!array_key_exists($field, $metadata)) { continue; }
      $metadata[$field] = self::digest($metadata[$field]);
    }
    return $metadata;
  }

  /**
   * 转换单个或批量标识
   *
   * @param mixed $value 缓存键或标签
   * @return string|array|null 稳定摘要或省略标记
   */
  private static function digest(mixed $value): string|array|null
  {
    if (is_string($value)) { return 'sha256:' . hash('sha256', $value); }
    if (!is_array($value)) { return null; }
    $result = [];
    foreach (array_slice($value, 0, 100) as $key) {
      $result[] = is_string($key) ? 'sha256:' . hash('sha256', $key) : '[OMITTED]';
    }
    if (count($value) > 100) { $result[] = '[TRUNCATED]'; }
    return $result;
  }
}
