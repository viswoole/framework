<?php

declare(strict_types=1);

namespace Viswoole\Monitor;

/** 职责：决定 SQL 展示信息的隐私边界；不解析 SQL，也不改写实际执行文本。 */
final class SqlCapture
{
  /**
   * 默认以稳定摘要替代可能含任意方言字面量或注释的 SQL 原文
   * @param array<string,mixed> $metadata 操作元数据
   * @param bool $canRecordSql 是否显式允许记录原文
   * @return array<string,mixed> 可交给通用字段脱敏器的副本
   */
  public static function sanitize(array $metadata, bool $canRecordSql): array
  {
    if (($metadata['type'] ?? null) !== 'database' || !is_string($metadata['sql'] ?? null) || $canRecordSql) {
      return $metadata;
    }
    // 不用不完整的方言解析猜测敏感边界；摘要仍可关联完全相同的语句。
    $metadata['sql'] = 'sha256:' . hash('sha256', $metadata['sql']);
    return $metadata;
  }
}
