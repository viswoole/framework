<?php

/**
 * 职责：原子提交队列状态与集合索引；沿用缓存连接生命周期并保留载荷原始字节。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Driver\Redis;

use JsonException;
use RedisException;
use RuntimeException;
use Throwable;
use Viswoole\Cache\Exception\QueueCommitUnknownException;
use Viswoole\Cache\Contract\QueueStoreInterface;

/**
 * 为 Redis 驱动提供可靠队列的版本比较与执行权校验。
 */
trait QueueOperations
{
  /**
   * 声明 Redis 驱动具备可靠队列能力
   * @return bool Redis 提供完整原子状态提交能力
   */
  public function supportsQueueOperations(): bool
  {
    return true;
  }

  /**
   * 通过 Lua 原子校验执行权或版本后提交状态与索引变更
   * @param string $key 任务键
   * @param array $change 期望值、新值与索引变更
   * @return bool 是否成功提交
   * @throws RedisException 提交前 Redis 读取或获取连接失败时抛出
   * @throws QueueCommitUnknownException 原子提交回执不确定时抛出
   * @throws RuntimeException 脚本读取、信封解析或提交失败时抛出
   * @throws JsonException 状态元数据编码失败时抛出
   */
  public function updateQueue(string $key, array $change): bool
  {
    static $script = null;
    $script ??= file_get_contents(__DIR__ . '/queue-update.lua');
    if ($script === false) { throw new RuntimeException('读取队列原子脚本失败'); }
    $raw = $this->withConnection(fn (\Redis $redis): mixed => $redis->get($this->getCacheKey($key)));
    if ($change['expected'] !== null && $raw === false) { return false; }
    if ($change['expected'] === null && $raw !== false) { return false; }
    $arguments = $this->queueArguments($key, $change, $raw);
    $isCommandStarted = false;
    // 获取连接失败可以证明未提交；进入 EVAL 后的执行或连接归还故障必须保守处理。
    try {
      $result = $this->withConnection(function (\Redis $redis) use ($script, $arguments, &$isCommandStarted): mixed {
        $isCommandStarted = true;
        return $redis->eval($script, $arguments, 11);
      });
    } catch (Throwable $exception) {
      if (!$isCommandStarted) { throw $exception; }
      throw new QueueCommitUnknownException('队列原子提交结果未知：' . $key, 0, $exception);
    }
    if ($result === false) { throw new QueueCommitUnknownException('队列原子提交未取得确认：' . $key); }
    return $result === 1;
  }

  /**
   * 构建当前协议的状态参数，保留首次持久化的业务载荷字节
   * @param string $key 任务键
   * @param array $change 条件与变更
   * @param string|false $raw Redis 原始值
   * @return array 脚本键与参数
   * @throws JsonException 元数据编码失败时抛出
   * @throws RuntimeException 队列信封损坏时抛出
   */
  private function queueArguments(string $key, array $change, string|false $raw): array
  {
    $remove = $change['remove'] ?? '';
    $add = $change['add'] ?? '';
    $previous = $raw === false ? null : $this->decodeStoredQueue($key, $raw);
    $payload = $previous['payload'] ?? null;
    $value = $change['value'];
    $encoded = $value === null ? '' : QueueRecord::encode(
      $value,
      $payload ?? $this->encodeQueuePayload($value)
    );
    return [$this->getCacheKey($key), $this->getCacheKey($remove),
      $this->getCacheKey($add), $this->getCacheKey($this->getTagStoreName()),
      $this->getCacheKey($change['target'] ?? $key), $this->getCacheKey(QueueStoreInterface::SCHEDULE_KEY),
      $this->getCacheKey(QueueStoreInterface::DIRECTORY_KEY),
      $this->getCacheKey(QueueStoreInterface::QUERY_METADATA_KEY), $this->getCacheKey(QueueStoreInterface::QUERY_IDS_KEY),
      $this->queueAssignmentKey($previous['metadata'] ?? null), $this->queueAssignmentKey($value),
      json_encode($change['expected'] === null ? null : QueueRecord::metadata($change['expected']), JSON_THROW_ON_ERROR), $encoded,
      serialize($key), $remove === '' ? '' : serialize($remove),
      $add === '' ? '' : serialize($add), serialize($change['target'] ?? $key),
      (string)($change['deadline'] ?? 0),
      QueueRecord::PREFIX, (int)($change['capacity'] ?? 0)];
  }

  /**
   * 拒绝序列化器的失败返回值，防止生成无法消费的已提交记录。
   * @param array $value 首次持久化记录
   * @return string 序列化器返回的原始字节
   * @throws RuntimeException 序列化器未返回字符串时抛出
   * @throws \Throwable 自定义序列化器执行失败时抛出
   */
  private function encodeQueuePayload(array $value): string
  {
    $payload = $this->serialize($value);
    if (!is_string($payload)) { throw new RuntimeException('队列载荷序列化器必须返回字符串'); }
    return $payload;
  }

  /**
   * 恢复业务数据并采用信封中的最新元数据，不让对象钩子改变状态判断依据
   * @param string $value 队列信封
   * @return array 完整任务记录
   * @throws JsonException 信封 JSON 损坏时抛出
   * @throws RuntimeException 信封或原始载荷结构损坏时抛出
   */
  private function unpackQueueRecord(string $value): array
  {
    $envelope = QueueRecord::decode($value);
    return $this->decodeQueueDelivery($envelope['metadata'] + ['queuePayload' => $envelope['payload']]);
  }
  /**
   * 只在实际消费或显式管理读取时恢复业务对象。
   * @param array $delivery 含原始字节的投递信封
   * @return array 完整业务任务
   * @throws \Throwable 序列化载荷或对象恢复失败时抛出
   */
  public function decodeQueueDelivery(array $delivery): array
  {
    if (!is_string($delivery['queuePayload'] ?? null)) { throw new RuntimeException('缺少队列投递载荷字节'); }
    $original = $this->unserialize($delivery['queuePayload']);
    if (!is_array($original)) { throw new RuntimeException('队列原始载荷必须为数组'); }
    $record = QueueRecord::restoreError(QueueRecord::metadata($delivery));
    if (array_key_exists('data', $original)) { $record['data'] = $original['data']; }
    return $record;
  }
}
