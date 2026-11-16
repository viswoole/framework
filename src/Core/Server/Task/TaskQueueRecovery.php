<?php

/*
 * 职责：恢复到期投递、记录首次投递失败及隔离损坏数据；不决定生产者受理结果。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use Closure;
use Viswoole\Cache\Exception\QueueRecordException;

/**
 * 已受理任务的恢复入口，复用队列的租约和原子状态边界。
 */
trait TaskQueueRecovery
{
  /**
   * 每轮限量恢复到期任务，单条任务异常不阻止本轮其他任务
   * @param Closure $dispatch 投递回调
   * @return void
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function recover(Closure $dispatch): void
  {
    foreach ($this->store->getDueQueue() as $id) {
      try {
        $this->dispatch($id, $dispatch);
      } catch (QueueRecordException $exception) {
        $this->isolateRecord($exception);
      } catch (\Throwable $exception) {
        TaskQueueLog::write('队列任务恢复异常：' . $id, ['exception' => (string)$exception]);
      }
    }
  }

  /**
   * 投递队列任务，仅在租约与状态允许时执行
   * @param string $queueId 任务 ID
   * @param Closure $dispatch Swoole 投递回调
   * @return int|false 投递结果
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function dispatch(string $queueId, Closure $dispatch): int|false
  {
    $before = $this->store->getQueueMetadata($queueId);
    if (!is_array($before)) {
      $this->store->pruneMissingQueue($queueId);
      return false;
    }
    if (($before['queueState'] ?? '') === 'failed') { return false; }
    // 到期判定使用存储端时钟，与 Redis TIME 的调度扫描保持一致
    $now = $this->store->now();
    if (($before['leaseUntil'] ?? 0) > $now || ($before['retryAt'] ?? 0) > $now) { return false; }
    $data = $before;
    if (($data['queueState'] ?? '') === 'running') {
      $this->transitionFailure($before, '执行租约到期，消费者可能已退出', ['isExpired' => true]);
      return false;
    }
    $data = $this->lease($data, 'dispatched');
    if (!$this->commit($before, $data)) { return false; }
    try {
      $delivery = $this->store->getQueueDelivery($queueId);
      if (!is_array($delivery) || $delivery['queueToken'] !== $data['queueToken']) { return false; }
      $result = $dispatch($delivery);
    } catch (\Throwable $exception) {
      $this->deliveryFailed($data, $exception->getMessage());
      throw $exception;
    }
    if ($result === false) { $this->deliveryFailed($data, 'Swoole 投递失败'); }
    return $result;
  }

  /**
   * 标记投递失败并安排退避重试
   * @param array $data 投递载荷
   * @param string $reason 投递失败原因
   * @return void
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function deliveryFailed(array $data, string $reason): void
  {
    $before = $this->owned($data, 'dispatched');
    if ($before === null) { return; }
    $after = $before;
    $after['queueState'] = 'retry';
    $after['leaseUntil'] = 0;
    $after['retryAt'] = $this->store->now() + $this->delay(1);
    $after['lastError'] = $reason;
    $this->commit($before, $after, true);
  }
  /**
   * 隔离只接受异常携带的原始快照，不能误处理并发修复的新版本。
   * @param QueueRecordException $exception 解析失败快照
   * @return void
   */
  private function isolateRecord(QueueRecordException $exception): void
  {
    try {
      $isQuarantined = $this->store->quarantineQueue($exception->queueId, $exception->snapshot, $exception->getMessage());
      $message = $isQuarantined ? '损坏任务已隔离：' : '损坏任务快照已变化，跳过隔离：';
      TaskQueueLog::write($message . $exception->queueId, ['exception' => (string)$exception]);
    } catch (\Throwable $failure) {
      TaskQueueLog::write('隔离任务失败，保留原记录等待重试：' . $exception->queueId, ['exception' => (string)$failure]);
    }
  }
  /**
   * 获取只读隔离记录入口。
   * @return TaskQuarantinedQueue 隔离记录查询器
   */
  public function quarantined(): TaskQuarantinedQueue
  {
    return new TaskQuarantinedQueue($this->store);
  }
}
