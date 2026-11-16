<?php

/*
 * 职责：提供持久化任务的只读元数据列表、统计及详情，不恢复业务对象。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use InvalidArgumentException;
use Viswoole\Cache\Contract\QueueStoreInterface;
use Viswoole\Cache\Driver\Redis\QueueRecord;

/**
 * 游标与统计不承诺跨请求快照；执行归属只在执行租约有效时展示。
 */
final readonly class TaskQueueQuery
{
  /**
   * 注入只读查询所需存储
   *
   * @param QueueStoreInterface $store 队列原子存储
   */
  public function __construct(
    /**
     * @var QueueStoreInterface 同商店查询边界
     */ private QueueStoreInterface $store,
  ) {}
  /**
   * 按条件列出元数据
   *
   * 每次最多扫描 1000 条索引；空列表仍可能有后续游标，调用方须检查 cursor。
   *
   * @param TaskQueueFilter $filter AND 筛选条件
   * @param string $cursor 上次返回的游标，空字符串表示从头开始
   * @param int $limit 返回上限，1 至 100
   * @return array{list:list<array<string,mixed>>,cursor:string,errors:list<array{queueId:string,reason:string}>,isComplete:bool} 元数据、游标及完整性
   * @throws \Throwable 参数无效或存储查询失败
   */
  public function list(TaskQueueFilter $filter = new TaskQueueFilter(), string $cursor = '', int $limit = 20): array
  {
    $page = $this->store->queryQueueMetadata($filter->toArray(), $cursor, $limit);
    return ['list' => array_map($this->project(...), $page['list']), 'cursor' => $page['cursor'],
      'errors' => $page['errors'], 'isComplete' => $page['isComplete']];
  }
  /**
   * 统计匹配任务总数及状态数量
   *
   * 分批只读扫描元数据，复杂度与商店任务数量相关，并发变化时不提供快照保证。
   * 损坏记录不计入数量，errors 标识未知部分，isComplete=false 时不能把总数当精确值。
   *
   * @param TaskQueueFilter $filter AND 筛选条件
   * @return array{total:int,dispatched:int,running:int,retry:int,failed:int,errors:list<array{queueId:string,reason:string}>,isComplete:bool} 有效记录数量及完整性
   * @throws \Throwable 存储查询失败
   */
  public function count(TaskQueueFilter $filter = new TaskQueueFilter()): array
  {
    $counts = ['total' => 0, 'dispatched' => 0, 'running' => 0, 'retry' => 0, 'failed' => 0];
    $cursor = '';
    $errors = [];
    do {
      $page = $this->store->queryQueueMetadata($filter->toArray(), $cursor, 100);
      foreach ($page['list'] as $metadata) {
        $counts['total']++;
        $counts[$metadata['queueState']]++;
      }
      foreach ($page['errors'] as $error) { $errors[] = $error; }
      $cursor = $page['cursor'];
    } while ($cursor !== '');
    return [...$counts, 'errors' => $errors, 'isComplete' => $errors === []];
  }
  /**
   * 查看任务元数据详情
   *
   * 不返回业务载荷、执行令牌及内部比较版本；已 ACK、删除或隔离时返回 null。
   *
   * @param string $queueId 队列身份
   * @return array<string,mixed>|null 只读元数据
   * @throws \Throwable 身份非法或读取失败
   */
  public function get(string $queueId): ?array
  {
    if (!preg_match('/^[a-f0-9]{48}$/D', $queueId)) { throw new InvalidArgumentException('队列 ID 格式无效'); }
    $metadata = $this->store->inspectQueueMetadata($queueId);
    return $metadata === null ? null : $this->project($metadata);
  }
  /**
   * 投影安全的查询元数据
   *
   * 过期运行状态保留用于故障排查，但不把旧消费者显示为仍在执行。
   *
   * @param array $metadata 含存储端观测时间的内部元数据
   * @return array<string,mixed> 无载荷和执行凭证的查询结果
   */
  private function project(array $metadata): array
  {
    $metadata = QueueRecord::restoreError($metadata);
    $metadata['state'] = $metadata['queueState'];
    $isExpired = $metadata['queueState'] === 'running' && $metadata['leaseUntil'] <= $metadata['observedAt'];
    $metadata['executionLeaseExpired'] = $isExpired;
    if ($isExpired || $metadata['queueState'] !== 'running') {
      $metadata['executionInstanceId'] = null;
      $metadata['executionWorkerId'] = null;
    }
    unset($metadata['queueToken'], $metadata['queueRevision'], $metadata['data'], $metadata['queuePayload']);
    return $metadata;
  }
}
