<?php

declare(strict_types=1);

/* 职责：描述采集字段状态，不再将日志占位字符串混入数据属性类型。 */

namespace Viswoole\Monitor\Dto;

/** 字段是否可用、被隐藏或因限制而省略。 */
enum CaptureState: string
{
  case CAPTURED = 'captured';
  case NOT_CAPTURED = 'not_captured';
  case REDACTED = 'redacted';
  case TRUNCATED = 'truncated';
  case OMITTED = 'omitted';

  /**
   * 采集占位标记到状态的显式映射
   *
   * 不允许子串启发式（如包含 LIMIT）判定：业务内容（如 "RATE LIMIT"）
   * 可能合法包含占位词片段，会被误分类并丢弃内容。
   */
  private const array PLACEHOLDERS = [
    '[CAPTURE_DISABLED]' => self::NOT_CAPTURED,
    '[REDACTED]' => self::REDACTED,
    '[TRUNCATED]' => self::TRUNCATED,
    '[DEPTH_OR_NODE_LIMIT]' => self::TRUNCATED,
    '[BODY_LIMIT]' => self::TRUNCATED,
    '[SNAPSHOT_LIMIT]' => self::TRUNCATED,
    '[UNSTRUCTURED_BODY]' => self::OMITTED,
    '[UNPARSEABLE_BODY]' => self::OMITTED,
    '[NON_FINITE]' => self::OMITTED,
  ];

  /**
   * 从旧日志值解析采集状态
   *
   * @param mixed $value 已安全复制的字段值
   * @return self 明确的采集状态
   */
  public static function resolve(mixed $value): self
  {
    if ($value === null) { return self::NOT_CAPTURED; }
    if (is_array($value)) { return ($value['__truncated'] ?? false) ? self::TRUNCATED : self::CAPTURED; }
    // 非占位符的普通业务字符串不属于采集控制标记，归入省略状态
    return is_string($value) ? (self::fromPlaceholder($value) ?? self::OMITTED) : self::OMITTED;
  }

  /**
   * 解析确切的占位标记字符串
   *
   * @param string $value 待判定的字符串
   * @return self|null 命中占位标记时返回对应状态，普通业务字符串返回 null
   */
  public static function fromPlaceholder(string $value): ?self
  {
    return self::PLACEHOLDERS[$value] ?? null;
  }
}
