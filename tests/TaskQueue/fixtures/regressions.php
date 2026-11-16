<?php
/**
 * 职责：验证真实定时续租、进程退出恢复与存储故障边界。
 */
declare(strict_types=1);
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 处理器跨越原租约期限，恢复扫描仍不能取得投递权。
 * @param QueueTestRedis $store 独立商店
 * @return array 定时续租和资源回收结果
 */
function observeAutomaticRenewal(QueueTestRedis $store, Swoole\Server $server): array
{
  $queue = new TaskQueue($store, 1);
  $manager = new QueueTestManager($store, 1);
  $observed = [];
  $manager->register('long', static function (\Viswoole\Core\Server\Task\TaskProxy $proxy) use ($queue, $store, &$observed): int {
    Swoole\Coroutine\System::sleep(1.4);
    $current = $store->get($proxy->queue_id);
    $observed['automaticRenewal'] = $current['leaseUntil'] > microtime(true);
    $observed['runningNotRecovered'] = $queue->dispatch($proxy->queue_id, static fn(): int => 1) === false;
    return 42;
  });
  $data = $queue->persist(['topic' => 'long'], 'long:0');
  $before = Swoole\Timer::stats()['num'] ?? 0;
  Swoole\Coroutine\run(static function () use ($manager, $data, $server, &$observed): void {
    $observed['longResult'] = $manager->accept($data, $server) === 42;
  });
  $observed['renewalStopped'] = (Swoole\Timer::stats()['num'] ?? 0) === $before;
  $observed['longAcked'] = !$store->has($data['queueId']);
  return $observed;
}

/**
 * 模拟子进程崩溃并观测恢复扫描的接管结果
 * @param string $prefix 隔离命名空间
 * @param QueueTestRedis $store 测试商店
 * @return array 真实租约过期后的接管结果
 */
function observeProcessCrash(string $prefix, QueueTestRedis $store): array
{
  $queue = new TaskQueue($store, 1);
  $data = $queue->persist(['topic' => 'demo'], 'crashed:0');
  $process = new Swoole\Process(static function () use ($prefix, $data): void {
    $child = new TaskQueue(new QueueTestRedis($prefix), 1);
    exit($child->consume($data) === null ? 1 : 0);
  });
  if ($process->start() === false) throw new RuntimeException('启动崩溃场景失败');
  $status = Swoole\Process::wait(true);
  if ($status === false || $status['code'] !== 0) throw new RuntimeException('崩溃场景未取得执行权');
  usleep(1100000);
  $queue->recover(static fn(): int => 0);
  $current = $store->get($data['queueId']);
  return ['actualCrashRecovered' => $current['queueState'] === 'retry' && $current['attempts'] === 1];
}

/**
 * 观测过期 CAS 提交与不支持驱动的拒绝行为
 * @param QueueTestRedis $store 测试商店
 * @return array 不匹配更新不会破坏状态与索引
 */
function observeAtomicBoundaries(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store);
  $data = $queue->persist(['topic' => 'demo'], 'atomic:0');
  $lease = $queue->consume($data);
  $snapshot = $store->get($data['queueId']);
  $isChanged = $store->updateQueue($data['queueId'], ['expected' => $data, 'value' => null,
    'remove' => $store->getTagKey(TaskQueue::TAG_PREFIX . 'atomic:0')]);
  $results = ['staleCasRejected' => !$isChanged && $store->get($data['queueId']) === $snapshot];
  $results['fileRejected'] = !TaskQueue::isSupported(new \Viswoole\Cache\Driver\File(storage: sys_get_temp_dir() . '/queue-file-unused'));
  $results['wrappedFileRejected'] = !TaskQueue::isSupported(new \Viswoole\Cache\MonitoredDriver(
    new \Viswoole\Cache\Driver\File(storage: sys_get_temp_dir() . '/queue-file-unused'), 'file'));
  $queue->ack($lease);
  return $results;
}

/**
 * 观测存储故障下的状态保留与恢复提交
 * @param QueueTestRedis $store 测试商店
 * @return array 存储故障和 Redis 内租约校验结果
 */
function observeStorageFailures(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store);
  $data = $queue->persist(['topic' => 'demo'], 'fault:0');
  $lease = $queue->consume($data);
  $snapshot = $store->get($data['queueId']);
  $store->hasUpdateFailure = true;
  try {
    $queue->ack($lease);
    throw new RuntimeException('预期 ACK 存储故障抛出异常');
  } catch (RedisException $exception) {
    $results['ackFailureRetained'] = $store->get($data['queueId']) === $snapshot;
  } finally {
    $store->hasUpdateFailure = false;
  }
  $results['ackAfterStoreRecovery'] = $queue->ack($lease);
  $shortQueue = new TaskQueue($store, 1);
  $data = $shortQueue->persist(['topic' => 'demo'], 'deadline:0');
  $shortQueue->consume($data);
  usleep(1100000);
  $results['serverDeadline'] = !$store->updateQueue($data['queueId'], [
    'expected' => $store->get($data['queueId']), 'value' => null, 'deadline' => microtime(true) + 30,
  ]) && $store->has($data['queueId']);
  return $results;
}
