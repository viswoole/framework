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
   * @var int 到期集合扫描位置，仅在当前消费进程内保留，重启后从头扫描
   */
  private int $recoveryOffset = 0;

  /**
   * 每轮限量恢复到期任务，单条任务异常不阻止本轮其他任务
   * @param Closure $dispatch 投递回调
   * @param array $options 本机节点、容量、主题及停机检查
   * @return int 本轮成功交给本机 Swoole 的任务数
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function recover(Closure $dispatch, array $options = []): int
  {
    $deadline = $options === [] ? PHP_INT_MAX : hrtime(true) + 50_000_000;
    $processed = 0;
    $remaining = isset($options['capacity']) ? min(32, $options['capacity'] - $this->assignedCount($options['instanceId'])) : 100;
    $ids = $this->store->getDueQueue(100, $options === [] ? 0 : $this->recoveryOffset);
    $examined = 0;
    foreach ($ids as $id) {
      if (hrtime(true) >= $deadline || (isset($options['canContinue']) && !$options['canContinue']())) { break; }
      if ($remaining <= 0) { break; }
      $examined++;
      try {
        if ($this->dispatch($id, $dispatch, $options) !== false) {
          $processed++;
          $remaining--;
        }
      } catch (QueueRecordException $exception) {
        $this->isolateRecord($exception);
      } catch (\Throwable $exception) {
        TaskQueueLog::write('队列任务恢复异常：' . $id, ['exception' => (string)$exception]);
      }
    }
    // 无法消费的主题不修改共享调度时间；本机游标继续推进，到集合末尾后重新扫描。
    // 并发移除可能暂时跳过成员，但每次绕回都会重新覆盖，不会永久阻塞后续主题。
    if ($options !== []) {
      $this->recoveryOffset = $ids === [] || ($examined === count($ids) && count($ids) < 100) ? 0 : $this->recoveryOffset + $examined;
    }
    return $processed;
  }

  /**
   * 投递队列任务，仅在租约与状态允许时执行
   * @param string $queueId 任务 ID
   * @param Closure $dispatch Swoole 投递回调
   * @param array $target 可选节点身份、容量及可消费主题
   * @return int|false 投递结果
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function dispatch(string $queueId, Closure $dispatch, array $target = []): int|false
  {
    $before = $this->store->getQueueMetadata($queueId);
    if (!is_array($before)) {
      $this->store->pruneMissingQueue($queueId);
      return false;
    }
    if (($before['queueState'] ?? '') === 'failed') { return false; }
    if (isset($target['topics']) && !in_array(strtolower($before['topic']), $target['topics'], true)) { return false; }
    // 到期判定使用存储端时钟，与 Redis TIME 的调度扫描保持一致
    $now = $this->store->now();
    if (($before['leaseUntil'] ?? 0) > $now || ($before['retryAt'] ?? 0) > $now) { return false; }
    $data = $before;
    if (($data['queueState'] ?? '') === 'running') {
      $this->transitionFailure($before, '执行租约到期，消费者可能已退出', ['isExpired' => true]);
      return false;
    }
    $data = $this->lease($data, 'dispatched');
    if (isset($target['instanceId'])) {
      if ($target['instanceId'] === '' || !is_int($target['capacity'] ?? null) || $target['capacity'] < 1) {
        throw new \InvalidArgumentException('领取节点身份或容量无效');
      }
      $data['dispatchInstanceId'] = $target['instanceId'];
    }
    if (!$this->commit($before, $data, ['capacity' => $target['capacity'] ?? 0])) { return false; }
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
    $this->commit($before, $after, ['leaseRequired' => true]);
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
