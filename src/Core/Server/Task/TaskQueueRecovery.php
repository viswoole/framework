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
   * @var int 固定窗口内已绕过的成员数，本机成功移出的成员不计入后续排名
   */
  private int $recoveryOffset = 0;
  /**
   * @var float|null 当前绕行窗口的存储端时间上界，防止新入队记录无限延长本轮扫描
   */
  private ?float $recoveryDueBefore = null;

  /**
   * 每轮有界恢复到期任务
   *
   * 单条异常不阻止其余任务；固定绕行窗口避免持续入队让旧记录无限等待。
   *
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
    if ($remaining <= 0) { return 0; }
    if ($options !== []) { $this->recoveryDueBefore ??= $this->store->now(); }
    $ids = $this->store->getDueQueue(100, $options === [] ? 0 : $this->recoveryOffset, $options === [] ? null : $this->recoveryDueBefore);
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
    if ($options !== []) { $this->advanceRecoveryCursor($ids, $examined, $processed); }
    return $processed;
  }

  /**
   * 推进有界绕行窗口
   *
   * 成功领取会将成员移出当前到期窗口，因此偏移只增加仍被绕过的成员数，不重复扫描稳定的未知主题前缀。
   * 其他节点移除或失败投递可能让排名暂时变化，固定窗口结束后重新覆盖，持续新入队不能阻止回绕。
   *
   * @param array $ids 当前窗口页
   * @param int $examined 本页已检查数量
   * @param int $processed 成功交接数量
   * @return void 本机扫描位置已更新，不修改任何任务的共享到期时间
   */
  private function advanceRecoveryCursor(array $ids, int $examined, int $processed): void
  {
    if ($ids === [] || ($examined === count($ids) && count($ids) < 100)) {
      $this->recoveryOffset = 0;
      $this->recoveryDueBefore = null;
      return;
    }
    // 只扣除已经确认离开当前窗口的本机成功领取，不能假设并发节点也移除了多少成员。
    $this->recoveryOffset += $examined - $processed;
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
