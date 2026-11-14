<?php

declare(strict_types=1);

/* 职责：为参数、文件字段等集合提供稳定数组与独立状态。 */

namespace Viswoole\Monitor\Dto;

/**
 * 已采集集合
 *
 * values 始终是数组，整组隐藏时为空；内部单个字段仍可能含脱敏标记。
 */
final readonly class CollectionSnapshot extends CollectionRecord
{
  /** @var array<array-key,mixed> 开放业务数据，内部仅含安全标量与数组 */
  public array $values;

  /**
   * 解析已脱敏的集合
   *
   * @param mixed $value 数组或日志占位状态
   */
  public function __construct(mixed $value)
  {
    parent::__construct($value);
    $values = $this->collectionValues();
    $this->values = $values;
  }
}
