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
   * 读取节点有效任务数
   *
   * 先回收过期成员，时间统一采用 Redis TIME；此查询只作预筛，容量上限由提交脚本保证。
   *
   * @param string $instanceId 节点身份
   * @return int 仍有有效投递或执行租约的数量
   * @throws \Throwable Redis 查询失败或节点身份无效
   */
  public function getQueueAssignmentCount(string $instanceId): int
  {
    if ($instanceId === '') { throw new InvalidArgumentException('节点身份不能为空'); }
    $script = <<<'LUA'
      local now = redis.call('TIME')
      redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', tonumber(now[1]) + tonumber(now[2]) / 1000000)
      return redis.call('ZCARD', KEYS[1])
      LUA;
    return (int)$this->withConnection(fn (\Redis $redis): mixed => $redis->eval(
      $script,
      [$this->queueAssignmentKey(['dispatchInstanceId' => $instanceId])],
      1
    ));
  }

  /**
   * 解析节点容量索引键
   *
   * 节点身份只作为哈希输入，索引与普通任务使用同一个商店前缀。
   *
   * @param array|null $record 任务状态或节点身份
   * @return string 底层 Redis 键
   */
  private function queueAssignmentKey(?array $record): string
  {
    return $this->getCacheKey('$_TASK_NODE_ASSIGNMENTS_:' . hash('sha256', $record['dispatchInstanceId'] ?? ''));
  }

  /**
   * 查询指定窗口内的到期任务
   *
   * 读取不移除成员，认领依赖状态脚本去重；固定上界用于保证绕行窗口可以结束。
   *
   * @param int $limit 单轮数量上限
   * @param int $offset 到期集合起始偏移，用于跳过本轮无法领取的任务
   * @param float|null $dueBefore 固定扫描时间上界，null 使用当前 Redis 时间，超过当前时间时自动截断
   * @return list<string> 到期任务标识
   * @throws \Throwable Redis 操作失败时抛出
   */
  public function getDueQueue(int $limit = 100, int $offset = 0, ?float $dueBefore = null): array
  {
    if ($limit < 1 || $limit > 1000) { throw new InvalidArgumentException('队列恢复批量大小必须在 1 到 1000 之间'); }
    if ($offset < 0) { throw new InvalidArgumentException('队列扫描偏移不能小于0'); }
    if ($dueBefore !== null && (!is_finite($dueBefore) || $dueBefore < 0)) { throw new InvalidArgumentException('队列扫描时间上界必须为非负有限值'); }
    $script = <<<'LUA'
      local now = redis.call('TIME')
      local observedAt = tonumber(now[1]) + tonumber(now[2]) / 1000000
      local deadline = ARGV[3] == '' and observedAt or math.min(observedAt, tonumber(ARGV[3]))
      return redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', deadline,
        'LIMIT', tonumber(ARGV[2]), tonumber(ARGV[1]))
      LUA;
    $ids = $this->withConnection(fn (\Redis $redis): mixed => $redis->eval(
      $script,
      [$this->getCacheKey(QueueStoreInterface::SCHEDULE_KEY), $limit, $offset, $dueBefore === null ? '' : sprintf('%.6F', $dueBefore)],
      1
    ));
    if (!is_array($ids)) { throw new RuntimeException('查询到期队列任务失败'); }
    return array_map(static fn (string $id): string => unserialize($id, ['allowed_classes' => false]), $ids);
  }

  /**
   * 以 Redis TIME 读取存储端时钟，作为租约与调度时间的唯一时钟域。
   * 应用与 Redis 可能跨机部署，使用应用时钟会造成租约判定与到期扫描的偏差。
   * @return float 存储端当前时间戳（浮点秒）
   * @throws \Throwable Redis 操作失败时抛出
   */
  public function now(): float
  {
    $time = $this->withConnection(fn (\Redis $redis): array => $redis->time());
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
    $raw = $this->withConnection(fn (\Redis $redis): mixed => $redis->get($this->getCacheKey($key)));
    if ($raw === false) { return null; }
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
    $raw = $this->withConnection(fn (\Redis $redis): mixed => $redis->get($this->getCacheKey($key)));
    if ($raw === false) { return null; }
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
      if (!QueueRecord::isEncoded($raw)) { throw new RuntimeException('队列信封标识无效'); }
      $envelope = QueueRecord::decode($raw);
      if ($envelope['metadata']['queueId'] !== $key) { throw new RuntimeException('任务标识与存储键不一致'); }
      return $envelope;
    } catch (\JsonException|RuntimeException $exception) {
      throw new QueueRecordException($key, $raw, $exception);
    }
  }

}
