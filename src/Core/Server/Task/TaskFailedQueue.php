<?php

/**
 * 职责：查询、重放及删除失败任务；不直接执行或投递业务任务。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use Viswoole\Cache\Contract\QueueStoreInterface;

/**
 * 失败记录永久保留，所有管理变更均以原状态为条件提交。
 */
final readonly class TaskFailedQueue
{
  /**
   * 初始化失败队列管理器
   * @param QueueStoreInterface $store 与活跃队列相同的缓存商店
   */
  public function __construct(
    /**
     * @var QueueStoreInterface 与活跃队列共享的原子存储
     */ private QueueStoreInterface $store
  )
  {
  }

  /**
   * 按任务 ID 稳定排序；并发变动时分页不提供快照保证。
   * @param int $page 页码
   * @param int $pageSize 每页数量，上限 100
   * @return array 失败任务记录
   * @throws \InvalidArgumentException 页码或每页数量不合法时抛出
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function list(int $page = 1, int $pageSize = 20): array
  {
    if ($page < 1 || $pageSize < 1 || $pageSize > 100) { throw new \InvalidArgumentException(
      '失败任务分页参数无效'
    ); }
    $ids = $this->store->getArray($this->store->getTagKey(TaskQueue::FAILED_TAG)) ?: [];
    sort($ids, SORT_STRING);
    $records = [];
    foreach (array_slice($ids, ($page - 1) * $pageSize, $pageSize) as $id) {
      $record = $this->get($id);
      if ($record !== null) { $records[] = $record; }
    }
    return $records;
  }

  /**
   * 读取单条失败任务记录
   * @param string $id 失败任务 ID
   * @return array|null 完整失败记录
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function get(string $id): ?array
  {
    $data = $this->store->get($id);
    return is_array($data) && ($data['queueState'] ?? '') === 'failed' ? $data : null;
  }

  /**
   * 原子移动为新任务，刷新有效期起点；并发重放只有一个胜者。
   * @param string $id 失败任务 ID
   * @return string|false 新任务 ID，记录不存在或已被其他管理操作修改时返回 false
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function retry(string $id): string|false
  {
    $before = $this->get($id);
    if ($before === null) { return false; }
    $after = $before;
    $after['originalQueueId'] = $before['originalQueueId'] ?? $id;
    $after['queueId'] = bin2hex(random_bytes(24));
    $after['queueState'] = 'retry';
    $after['executionInstanceId'] = null;
    $after['executionWorkerId'] = null;
    $after['queueToken'] = '';
    $after['attempts'] = 0;
    $after['retryAt'] = 0;
    $after['dispatch_time'] = microtime(true);
    unset($after['lastError'], $after['lastErrorBase64'], $after['failedAt']);
    $isChanged = $this->store->updateQueue($id, [
      'expected' => $before, 'value' => $after, 'target' => $after['queueId'],
      'remove' => $this->store->getTagKey(TaskQueue::FAILED_TAG),
      'add' => $this->store->getTagKey(TaskQueue::TAG_PREFIX . $after['queueGroup']),
    ]);
    return $isChanged ? $after['queueId'] : false;
  }

  /**
   * 原子删除失败任务记录
   * @param string $id 失败任务 ID
   * @return bool 是否删除成功
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function delete(string $id): bool
  {
    $before = $this->get($id);
    return $before !== null && $this->store->updateQueue($id, [
        'expected' => $before, 'value' => null,
        'remove' => $this->store->getTagKey(TaskQueue::FAILED_TAG),
      ]);
  }
}
