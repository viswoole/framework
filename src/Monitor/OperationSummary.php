<?php

declare(strict_types=1);

/* 职责：维护固定种类操作的轻量汇总；明细超限后仍保留耗时和失败统计。 */

namespace Viswoole\Monitor;

/**
 * 操作耗时汇总
 *
 * 分类只允许数据库、缓存与其他三组，不因用户元数据产生无界键集合。
 * 累计耗时可能包含重叠操作，不代表请求墙上时间。
 */
final class OperationSummary
{
  /** @var array<string,array{count:int,failed_count:int,duration_ms:float,max_duration_ms:float,dropped_duration_ms:float}> */
  private array $groups = [];

  /**
   * 累加已完成操作
   *
   * @param array{type:string,duration_ms:float,success:bool,dropped:bool} $operation 操作结果
   * @return void
   */
  public function record(array $operation): void
  {
    $type = OperationType::resolve($operation['type'])->value;
    $group = $this->groups[$type] ?? [
      'count' => 0, 'failed_count' => 0, 'duration_ms' => 0.0,
      'max_duration_ms' => 0.0, 'dropped_duration_ms' => 0.0,
    ];
    $group['count']++;
    $group['failed_count'] += $operation['success'] ? 0 : 1;
    $group['duration_ms'] += $operation['duration_ms'];
    $group['max_duration_ms'] = max($group['max_duration_ms'], $operation['duration_ms']);
    $group['dropped_duration_ms'] += $operation['dropped'] ? $operation['duration_ms'] : 0;
    $this->groups[$type] = $group;
  }

  /**
   * 返回独立汇总快照
   *
   * @return array<string,array{count:int,failed_count:int,duration_ms:float,max_duration_ms:float,dropped_duration_ms:float}> 三类汇总
   */
  public function snapshot(): array
  {
    return $this->groups;
  }
}
