<?php

declare(strict_types=1);

/* 职责：某类操作的计数和耗时统计；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

/**
 * 某类操作的计数和耗时统计 DTO
 *
 * 属性提供 IDE 类型提示；字段被整组脱敏时保留字符串标记，未采集字段为 null。
 */
final readonly class OperationStatistics extends SnapshotRecord
{
  public int $count;
  public int $failedCount;
  public float $durationMs;
  public float $maxDurationMs;
  public float $droppedDurationMs;

  /**
   * 构建定稿记录
   *
   * @param array<string,mixed> $data 已脱敏的记录字段
   */
  public function __construct(array $data)
  {
    parent::__construct($data);
    $safe = $this->toArray();
    $this->count = ($safe['count'] ?? 0);
    $this->failedCount = ($safe['failed_count'] ?? 0);
    $this->durationMs = (float)($safe['duration_ms'] ?? 0.0);
    $this->maxDurationMs = (float)($safe['max_duration_ms'] ?? 0.0);
    $this->droppedDurationMs = (float)($safe['dropped_duration_ms'] ?? 0.0);
  }
}
