<?php
/**
 * 职责：只读查询被隔离记录的原始字节和原因；不恢复对象或自动重放损坏数据。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use InvalidArgumentException;
use RuntimeException;
use Viswoole\Cache\Contract\QueueStoreInterface;

/**
 * 隔离记录默认保留，供定位协议或存储损坏问题。
 */
final readonly class TaskQuarantinedQueue
{
  /**
   * 绑定与队列相同的存储。
   * @param QueueStoreInterface $store 原子队列商店
   */
  public function __construct(
    /**
     * @var QueueStoreInterface 隔离记录所在商店
     */
    private QueueStoreInterface $store
  ) {
  }

  /**
   * 读取原始快照，不反序列化快照内的业务对象。
   * @param string $id 原始任务标识
   * @return array|null 包含 queueId、raw、reason、quarantinedAt 的记录
   * @throws \Throwable 存储读取失败时抛出
   */
  public function get(string $id): ?array
  {
    $record = $this->store->get(QueueStoreInterface::QUARANTINE_PREFIX . $id);
    return is_array($record) ? $this->restoreRecord($record) : null;
  }

  /**
   * 按记录键稳定排序，分页不提供并发快照保证。
   * @param int $page 页码
   * @param int $pageSize 每页条数，上限 100
   * @return list<array> 隔离记录
   * @throws \Throwable 参数无效或读取失败时抛出
   */
  public function list(int $page = 1, int $pageSize = 20): array
  {
    if ($page < 1 || $pageSize < 1 || $pageSize > 100) throw new InvalidArgumentException('隔离记录分页参数无效');
    $keys = $this->store->getArray($this->store->getTagKey(QueueStoreInterface::QUARANTINE_TAG)) ?: [];
    sort($keys, SORT_STRING);
    $records = [];
    foreach (array_slice($keys, ($page - 1) * $pageSize, $pageSize) as $key) {
      $record = $this->store->get($key);
      if (is_array($record)) $records[] = $this->restoreRecord($record);
    }
    return $records;
  }
  /**
   * 独立编码二进制快照，避免商店使用 JSON 序列化时丢失原始字节。
   * @param array $record 存储记录
   * @return array 还原后的快照和原因
   * @throws RuntimeException 隔离记录本身损坏时抛出
   */
  private function restoreRecord(array $record): array
  {
    if (!is_string($record['raw_base64'] ?? null) || !is_string($record['reason_base64'] ?? null)) {
      throw new RuntimeException('隔离记录缺少快照或失败原因');
    }
    $raw = base64_decode($record['raw_base64'], true);
    $reason = base64_decode($record['reason_base64'], true);
    if ($raw === false || $reason === false) throw new RuntimeException('隔离记录字节编码无效');
    return ['queueId' => $record['queueId'], 'raw' => $raw, 'reason' => $reason,
      'quarantinedAt' => $record['quarantinedAt']];
  }
}
