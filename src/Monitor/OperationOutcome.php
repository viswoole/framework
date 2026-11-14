<?php

declare(strict_types=1);

namespace Viswoole\Monitor;

/** 操作结果的日志策略；区分执行状态与缓存内容，不改变业务返回值。 */
final class OperationOutcome
{
  private const array CACHE_STATUS_OPERATIONS = [
    'set', 'delete', 'has', 'clear', 'unlock', 'ttl',
    'sAddArray', 'sRemoveArray', 'tag.set', 'tag.remove', 'tag.clear', 'tag.push',
  ];

  /** @var array<string> false 可表示未命中或无变化，不能据此判定基础设施失败 */
  private const array CACHE_MISS_OPERATIONS = ['get', 'pull', 'has', 'ttl', 'getArray', 'getTags', 'sAddArray'];

  /**
   * 创建可记录的结果摘要；未知缓存操作默认隐藏返回内容。
   * @param array $metadata 操作类型与名称
   * @param mixed $result 原始返回值
   * @return array{success:bool,result_type:string,result:mixed,result_count:?int} 日志摘要
   */
  public static function summarize(array $metadata, mixed $result): array
  {
    $isCache = ($metadata['type'] ?? '') === 'cache';
    $operation = (string)($metadata['operation'] ?? '');
    $canRecordValue = !$isCache || in_array($operation, self::CACHE_STATUS_OPERATIONS, true);
    return [
      // 部分驱动用 false 表示集合未命中或重复添加；异常仍由 measure 记录失败。
      'success' => !$isCache || $result !== false || in_array($operation, self::CACHE_MISS_OPERATIONS, true),
      'result_type' => get_debug_type($result),
      // get/pull/inc/dec 返回的数值、布尔值也可能是敏感缓存内容。
      'result' => $canRecordValue && is_scalar($result) && !is_string($result) ? $result : null,
      'result_count' => is_array($result) ? count($result) : null,
    ];
  }
}
