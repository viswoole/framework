<?php

/*
 * 职责：通过只读 Lua 查询独立元数据索引，不访问业务载荷键或修改任务。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Driver\Redis;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Viswoole\Cache\Contract\QueueStoreInterface;

/**
 * 限制每次扫描工作量，稀疏筛选可返回空页和非空游标。
 */
trait QueueInspection
{
  /**
   * 分批查询队列元数据
   *
   * @param array $filter 主题、实例及状态条件
   * @param string $cursor 字典序游标
   * @param int $limit 返回上限，1 至 100
   * @return array{list:list<array>,cursor:string,errors:list<array{queueId:string,reason:string}>,isComplete:bool} 元数据页及损坏报告
   * @throws \Throwable 筛选无效或 Redis 操作失败
   */
  public function queryQueueMetadata(array $filter = [], string $cursor = '', int $limit = 20): array
  {
    if ($limit < 1 || $limit > 100 || ($cursor !== '' && !preg_match('/^[a-f0-9]{48}$/D', $cursor))) {
      throw new InvalidArgumentException('队列查询游标或条数无效');
    }
    $normalized = $filter;
    foreach ($filter as $field => $value) {
      if (!in_array($field, ['producerInstanceId', 'executionInstanceId', 'topic', 'state'], true)
        || !is_string($value) || $value === '') { throw new InvalidArgumentException('队列筛选条件无效'); }
      if ($field === 'state' && !in_array($value, ['dispatched', 'running', 'retry', 'failed'], true)) { throw new InvalidArgumentException('队列状态无效'); }
      if ($field === 'topic') { $normalized[$field] = strtolower($value); }
    }
    $result = $this->readQueueIndex(['list', $cursor === '' ? '' : serialize($cursor), json_encode((object)$normalized, JSON_THROW_ON_ERROR), $limit]);
    return $this->decodeInspectionPage($result);
  }
  /**
   * 只读查询单条元数据
   *
   * @param string $queueId 队列身份
   * @return array|null 不含业务载荷的元数据
   * @throws \Throwable 身份无效或 Redis 操作失败
   */
  public function inspectQueueMetadata(string $queueId): ?array
  {
    if (!preg_match('/^[a-f0-9]{48}$/D', $queueId)) { throw new InvalidArgumentException('队列 ID 格式无效'); }
    try {
      $raw = $this->readQueueIndex(['detail', serialize($queueId)]);
      if (!is_string($raw)) { throw new RuntimeException('查询索引读取失败'); }
      return $raw === '' ? null : $this->decodeInspectionRecord($raw, $queueId);
    } catch (Throwable $exception) {
      throw new RuntimeException('队列详情查询失败：' . $queueId, 0, $exception);
    }
  }
  /**
   * 解码独立记录并验证元数据
   *
   * 校验失败原因编码和完整字段，避免 JSON 合法但结构无效的数据进入投影与统计。
   *
   * @param string $raw 单条 JSON
   * @param string $queueId 索引中的可信任务身份
   * @return array 已校验的内部元数据
   * @throws \Throwable 元数据损坏
   */
  private function decodeInspectionRecord(string $raw, string $queueId): array
  {
    $metadata = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($metadata) || ($metadata['queueId'] ?? null) !== $queueId) { throw new RuntimeException('队列查询元数据身份无效'); }
    QueueRecord::validateMetadata($metadata);
    return $metadata;
  }

  /**
   * 逐条解码查询页
   *
   * 单条损坏不阻断游标；保留错误身份，让调用方明确识别不完整结果。
   *
   * @param mixed $result Lua 查询回执
   * @return array{list:list<array>,cursor:string,errors:list<array{queueId:string,reason:string}>,isComplete:bool} 查询页
   * @throws RuntimeException 存储回执无效
   */
  private function decodeInspectionPage(mixed $result): array
  {
    if (!is_array($result) || count($result) !== 3) { throw new RuntimeException('队列元数据查询结果无效'); }
    $errors = array_map(static fn (array $error): array => [
      'queueId' => unserialize($error[0], ['allowed_classes' => false]), 'reason' => $error[1],
    ], $result[2]);
    $records = [];
    foreach ($result[0] as [$id, $raw]) {
      $queueId = unserialize($id, ['allowed_classes' => false]);
      try {
        $records[] = $this->decodeInspectionRecord($raw, $queueId);
      } catch (Throwable $exception) {
        $errors[] = ['queueId' => $queueId, 'reason' => $exception->getMessage()];
      }
    }
    return ['list' => $records,
      'cursor' => $result[1] === '' ? '' : unserialize($result[1], ['allowed_classes' => false]),
      'errors' => $errors, 'isComplete' => $errors === []];
  }

  /**
   * 执行只读索引脚本
   *
   * @param array $arguments 操作参数
   * @return mixed Redis 回执
   * @throws \Throwable 脚本读取或执行失败
   */
  private function readQueueIndex(array $arguments): mixed
  {
    static $script = null;
    $script ??= file_get_contents(__DIR__ . '/queue-query.lua');
    if ($script === false) { throw new RuntimeException('读取队列查询脚本失败'); }
    $keys = [$this->getCacheKey(QueueStoreInterface::QUERY_METADATA_KEY), $this->getCacheKey(QueueStoreInterface::QUERY_IDS_KEY)];
    return $this->withConnection(fn (\Redis $redis): mixed => $redis->eval($script, [...$keys, ...$arguments], 2));
  }
}
