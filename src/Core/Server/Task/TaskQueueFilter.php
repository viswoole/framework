<?php

/*
 * 职责：校验和表达只读队列筛选条件；不访问存储或执行任务。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use InvalidArgumentException;

/**
 * 所有条件按 AND 匹配，主题不区分大小写，实例身份精确匹配。
 */
final readonly class TaskQueueFilter
{
  /**
   * @var array{producerInstanceId?:string,executionInstanceId?:string,topic?:string,state?:string} 已校验条件
   */
  private array $filters;
  /**
   * 校验查询条件
   *
   * 空数组表示查询同商店全部持久化任务，不默认限制到本实例。
   *
   * @param array{producerInstanceId?:string,executionInstanceId?:string,topic?:string,state?:TaskQueueState} $filters 条件
   * @throws InvalidArgumentException 字段未知、为空或状态类型无效
   */
  public function __construct(array $filters = [])
  {
    $validated = [];
    foreach ($filters as $field => $value) {
      if ($field === 'state') {
        if (!$value instanceof TaskQueueState) { throw new InvalidArgumentException('状态必须为 TaskQueueState'); }
        $validated[$field] = $value->value;
        continue;
      }
      if (!in_array($field, ['producerInstanceId', 'executionInstanceId', 'topic'], true)
        || !is_string($value) || $value === '') { throw new InvalidArgumentException('队列筛选条件无效：' . $field); }
      $validated[$field] = $field === 'topic' ? strtolower($value) : $value;
    }
    $this->filters = $validated;
  }
  /**
   * 获取存储筛选条件
   *
   * @return array{producerInstanceId?:string,executionInstanceId?:string,topic?:string,state?:string} 已校验条件
   */
  public function toArray(): array { return $this->filters; }
}
