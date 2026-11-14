<?php

declare(strict_types=1);

/* 职责：定义可信的操作分类，避免输出脱敏或元数据截断影响内部统计。 */

namespace Viswoole\Monitor;

/**
 * 内部操作分类
 *
 * 未知操作归入 other，分类存于有界内部状态，不从日志展示字段反向读取。
 */
enum OperationType: string
{
  case DATABASE = 'database';
  case CACHE = 'cache';
  case OTHER = 'other';

  /**
   * 归一化内部分类
   *
   * @param mixed $type 采集入口的原始类型
   * @return self 已知操作分类或其他分类
   */
  public static function resolve(mixed $type): self
  {
    return match ($type) {
      'database' => self::DATABASE,
      'cache' => self::CACHE,
      default => self::OTHER,
    };
  }
}
