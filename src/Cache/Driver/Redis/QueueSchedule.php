<?php
/**
 * 职责：查询到期任务、状态元数据和不透明投递信封；不恢复业务对象或修改状态。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Driver\Redis;

use InvalidArgumentException;
use Viswoole\Cache\Contract\QueueStoreInterface;
use Viswoole\Cache\Exception\QueueRecordException;
use RuntimeException;

/**
 * 有序集合仅保存任务标识与下一次处理时间，避免周期扫描业务载荷。
 */
trait QueueSchedule
{
  /**
   * 以 Redis 时间查询到期任务，读取不移除成员，认领依赖状态脚本去重。
   * @param int $limit 单轮数量上限
   * @return list<string> 到期任务标识
   * @throws \Throwable Redis 操作失败时抛出
   */
  public function getDueQueue(int $limit = 100): array
  {
    if ($limit < 1 || $limit > 1000) throw new InvalidArgumentException('队列恢复批量大小必须在 1 到 1000 之间');
    $script = <<<'LUA'
      local now = redis.call('TIME')
      return redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', tonumber(now[1]) + tonumber(now[2]) / 1000000,
        'LIMIT', 0, tonumber(ARGV[1]))
      LUA;
    $ids = $this->withConnection(fn(\Redis $redis): mixed => $redis->eval($script,
      [$this->getCacheKey(QueueStoreInterface::SCHEDULE_KEY), $limit], 1));
    if (!is_array($ids)) throw new RuntimeException('查询到期队列任务失败');
    return array_map(static fn(string $id): string => unserialize($id, ['allowed_classes' => false]), $ids);
  }

  /**
   * 以 Redis TIME 读取存储端时钟，作为租约与调度时间的唯一时钟域。
   * 应用与 Redis 可能跨机部署，使用应用时钟会造成租约判定与到期扫描的偏差。
   * @return float 存储端当前时间戳（浮点秒）
   * @throws \Throwable Redis 操作失败时抛出
   */
  public function now(): float
  {
    $time = $this->withConnection(fn(\Redis $redis): array => $redis->time());
    return (float)$time[0] + (float)$time[1] / 1000000;
  }

  /**
   * 状态操作只接受当前队列信封，不反序列化业务对象。
   * @param string $key 任务标识
   * @return array|null 状态元数据
   * @throws \Throwable Redis 或协议解析失败时抛出
   */
  public function getQueueMetadata(string $key): ?array
  {
    $raw = $this->withConnection(fn(\Redis $redis): mixed => $redis->get($this->getCacheKey($key)));
    if ($raw === false) return null;
    return $this->decodeStoredQueue($key, $raw)['metadata'];
  }

  /**
   * 读取可直接通过 Swoole IPC 传输的字节信封。
   * @param string $key 任务标识
   * @return array|null 业务载荷尚未解码的信封
   * @throws \Throwable 存储或协议解析失败时抛出
   */
  public function getQueueDelivery(string $key): ?array
  {
    $raw = $this->withConnection(fn(\Redis $redis): mixed => $redis->get($this->getCacheKey($key)));
    if ($raw === false) return null;
    $envelope = $this->decodeStoredQueue($key, $raw);
    return $envelope['metadata'] + ['queuePayload' => $envelope['payload']];
  }

  /**
   * 为解析错误绑定读取时的原始快照，不能在捕获后重新取值并隔离。
   * @param string $key 任务标识
   * @param string $raw 原始字节
   * @return array{metadata: array, payload: string} 队列信封
   * @throws QueueRecordException 格式或元数据损坏时抛出
   */
  private function decodeStoredQueue(string $key, string $raw): array
  {
    try {
      if (!QueueRecord::isEncoded($raw)) throw new RuntimeException('队列信封标识无效');
      $envelope = QueueRecord::decode($raw);
      if ($envelope['metadata']['queueId'] !== $key) throw new RuntimeException('任务标识与存储键不一致');
      return $envelope;
    } catch (\JsonException|RuntimeException $exception) {
      throw new QueueRecordException($key, $raw, $exception);
    }
  }

}
