<?php

/**
 * 职责：按快照隔离损坏记录并回收缺失记录入口；不恢复业务对象或重放任务。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Driver\Redis;

use RuntimeException;
use Viswoole\Cache\Contract\QueueStoreInterface;

/**
 * 保存原始字节与失败原因，原子回收调度、分组及目录入口。
 */
trait QueueQuarantine
{
  /**
   * 快照不匹配时返回 false，调用方不得重新取值后无条件隔离。
   * @param string $key 任务标识
   * @param string $snapshot 解析失败的原始字节
   * @param string $reason 失败描述
   * @return bool 是否隔离成功
   * @throws \Throwable 脚本或存储操作失败时抛出
   */
  public function quarantineQueue(string $key, string $snapshot, string $reason): bool
  {
    $record = ['queueId' => $key, 'raw_base64' => base64_encode($snapshot),
      'reason_base64' => base64_encode($reason), 'quarantinedAt' => microtime(true)];
    $encoded = $this->serialize($record);
    if (!is_string($encoded)) { throw new RuntimeException('编码隔离记录失败：' . $key); }
    return $this->isolateQueue($key, ['mode' => 'quarantine', 'snapshot' => $snapshot, 'record' => $encoded]);
  }

  /**
   * 数据缺失时与隔离共用索引回收，不能留下分组或目录残留。
   * @param string $key 任务标识
   * @return void
   * @throws \Throwable Redis 操作失败时抛出
   */
  public function pruneMissingQueue(string $key): void
  {
    $this->isolateQueue($key, ['mode' => 'missing']);
  }

  /**
   * 使用同一脚本校验原始状态并回收全部入口。
   * @param string $key 任务标识
   * @param array{mode: string, snapshot?: string, record?: string} $options 校验与记录参数
   * @return bool 是否提交成功
   * @throws \Throwable 脚本读取或提交失败时抛出
   */
  private function isolateQueue(string $key, array $options): bool
  {
    static $script = null;
    $script ??= file_get_contents(__DIR__ . '/queue-quarantine.lua');
    if ($script === false) { throw new RuntimeException('读取队列隔离脚本失败'); }
    $recordKey = QueueStoreInterface::QUARANTINE_PREFIX . $key;
    $tag = $this->getTagKey(QueueStoreInterface::QUARANTINE_TAG);
    $arguments = [$this->getCacheKey($key), $this->getCacheKey($recordKey),
      $this->getCacheKey($tag), $this->getCacheKey($this->getTagStoreName()),
      $this->getCacheKey(QueueStoreInterface::SCHEDULE_KEY), $this->getCacheKey(QueueStoreInterface::DIRECTORY_KEY),
      $this->getCacheKey(QueueStoreInterface::QUERY_METADATA_KEY), $this->getCacheKey(QueueStoreInterface::QUERY_IDS_KEY),
      $options['snapshot'] ?? '', $options['record'] ?? '', serialize($key), serialize($recordKey), serialize($tag), $options['mode']];
    $result = $this->withConnection(fn (\Redis $redis): mixed => $redis->eval($script, $arguments, 8));
    if ($result === false) { throw new RuntimeException('提交队列隔离或回收失败：' . $key); }
    return $result === 1;
  }
}
