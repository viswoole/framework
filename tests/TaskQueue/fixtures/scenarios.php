<?php
/**
 * 职责：运行真实 Redis 的可靠消费场景；仅操作随机测试命名空间。
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reliable-support.php';
require __DIR__ . '/regressions.php';

use Viswoole\Core\Server\Task\TaskQueue;
use Viswoole\Core\Server\Task\TaskFailedQueue;

$prefix = 'queue-test:' . bin2hex(random_bytes(8)) . ':';
$store = new QueueTestRedis($prefix);
$queue = new TaskQueue($store, 1);
$queue->configureRetry(['max_attempts' => 2, 'base_seconds' => 1, 'max_seconds' => 2]);
$failed = new TaskFailedQueue($store);
$results = [];
$server = new Swoole\Server('127.0.0.1', 0, SWOOLE_BASE);
try {
  $data = $queue->persist(['topic' => 'demo', 'data' => 1], 'a:0');
  $results['initialBlocked'] = $queue->dispatch($data['queueId'], static fn(): int => 0) === false;
  $lease = $queue->consume($data);
  $results['retainedWhileRunning'] = $store->has($data['queueId']);
  $results['duplicateBlocked'] = $queue->consume($data) === null;
  $results['renewed'] = $queue->renew($lease);
  $results['acked'] = $queue->ack($lease) && !$store->has($data['queueId']);
  $results['indexRemoved'] = !in_array($store->getTagKey(TaskQueue::TAG_PREFIX . 'a:0'), $store->getTags(), true);

  $data = $queue->persist(['topic' => 'demo'], 'retry:0');
  $lease = $queue->consume($data);
  $queue->fail($lease, 'first error');
  $state = $store->get($data['queueId']);
  $results['backoff'] = $state['retryAt'] - $state['failedAt'];
  $results['retryBlocked'] = $queue->dispatch($data['queueId'], static fn(): int => 0) === false;
  makeDue($store, $data['queueId']);
  $next = deliver($queue, $data['queueId']);
  $lease = $queue->consume($next);
  $queue->fail($lease, 'second error');
  $results['exhausted'] = $failed->get($data['queueId'])['attempts'] === 2;
  $results['failedNotRecovered'] = $queue->dispatch($data['queueId'], static fn(): int => 0) === false;
  $newId = $failed->retry($data['queueId']);
  $results['replayed'] = is_string($newId) && $newId !== $data['queueId']
    && !$store->has($data['queueId']) && $store->get($newId)['originalQueueId'] === $data['queueId'];
  $results['replayOnce'] = $failed->retry($data['queueId']) === false;
  $next = deliver($queue, $newId);
  $lease = $queue->consume($next);
  $queue->fail($lease, 'terminal', true);
  $results['failedList'] = count($failed->list()) === 1;
  $results['failedDelete'] = $failed->delete($newId) && $failed->get($newId) === null;

  // CLI 协程 reactor 先完成并释放，再开始 fork 竞争，避免测试进程等待影响调度。
  $results += observeAutomaticRenewal($store, $server);
  $results += observeRecovery($queue, $store);
  $results += observeConsumers($queue, $store, $server);
  $results += observeCompetition($prefix, $queue, $store);
  $results += observeProcessCrash($prefix, $store);
  $results += observeAtomicBoundaries($store);
  $results += observeStorageFailures($store);
  $wrapped = new \Viswoole\Cache\MonitoredDriver($store, 'test');
  $wrappedQueue = new TaskQueue($wrapped);
  $data = $wrappedQueue->persist(['topic' => 'demo'], 'wrapped:0');
  $results['decorator'] = $wrappedQueue->ack($wrappedQueue->consume($data));
  echo json_encode($results, JSON_THROW_ON_ERROR);
} finally {
  $keys = $store->connect()->keys($prefix . '*');
  if ($keys !== []) $store->connect()->del($keys);
}
