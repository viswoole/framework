<?php

declare(strict_types=1);

/* 职责：观测索引故障、能力限制和分组回收竞争，不替换真实队列逻辑。 */

use Viswoole\Cache\Driver\File;
use Viswoole\Cache\MonitoredDriver;
use Viswoole\Core\Server\TaskQueue;

/**
 * 清理失败后仍执行处理器，扫描再次清理索引，不重新执行任务。
 * @param QueueTestRedis $store 测试驱动
 * @param Swoole\Server $server 未启动的服务对象
 * @return array 故障前后的消费和索引观测值
 */
function observeCleanupRecovery(QueueTestRedis $store, Swoole\Server $server): array
{
  $queue = new TaskQueue($store);
  $data = $queue->persist(['topic' => 'demo', 'data' => 7], 'cleanup:0');
  $manager = new QueueTestManager($store);
  $calls = 0;
  $manager->register('demo', static function () use (&$calls): int { return ++$calls; });
  $logPath = tempnam(sys_get_temp_dir(), 'queue-cleanup-');
  $previousLog = ini_set('error_log', $logPath);
  $store->hasPruneFailure = true;
  try {
    $result = $manager->accept($data, $server);
    $members = $store->tag(TaskQueue::TAG_PREFIX . 'cleanup:0')->get();
  } finally {
    $store->hasPruneFailure = false;
    ini_set('error_log', $previousLog);
  }
  $log = file_get_contents($logPath);
  unlink($logPath);
  $redeliveries = 0;
  $queue->recover(static function (array $data) use (&$redeliveries): int { return ++$redeliveries; });
  return ['result' => $result, 'calls' => $calls, 'staleMember' => $members === [$data['queueId']],
    'logged' => str_contains($log, 'injected prune failure'), 'redeliveries' => $redeliveries,
    'removed' => !in_array($store->getTagKey(TaskQueue::TAG_PREFIX . 'cleanup:0'), $store->getTags() ?: [], true)];
}

/**
 * 未注册主题的持久化任务在 onTask 中被消费清理，主题校验仍抛异常，
 * 恢复扫描不再发现该条目，避免无限重投循环。
 * @param QueueTestRedis $store 测试驱动
 * @param Swoole\Server $server 未启动的服务对象
 * @return array 异常路径后的数据与索引状态
 */
function observeUnknownTopicCleanup(QueueTestRedis $store, Swoole\Server $server): array
{
  $queue = new TaskQueue($store);
  $data = $queue->persist(['topic' => 'ghost', 'data' => 10], 'ghost:0');
  $manager = new QueueTestManager($store);
  $threw = false;
  try {
    $manager->accept($data, $server);
  } catch (InvalidArgumentException) {
    $threw = true;
  }
  $redeliveries = 0;
  $queue->recover(static function (array $data) use (&$redeliveries): int { return ++$redeliveries; });
  return ['threw' => $threw, 'dataExists' => $store->has($data['queueId']),
    'members' => $store->tag(TaskQueue::TAG_PREFIX . 'ghost:0')->get(),
    'redeliveries' => $redeliveries];
}

/**
 * File 在写入前拒绝持久化队列，监控包装器不能提升底层能力。
 * @param QueueTestRedis $store 测试 Redis 驱动
 * @param Swoole\Server $server 未启动服务对象
 * @return array 能力和包装器调用结果
 */
function observeStoreCapabilities(QueueTestRedis $store, Swoole\Server $server): array
{
  $path = sys_get_temp_dir() . '/unsupported-queue-' . bin2hex(random_bytes(8));
  $file = new File(storage: $path);
  $wrappedFile = new MonitoredDriver($file, 'file');
  $rejected = 0;
  foreach ([$file, $wrappedFile] as $driver) {
    try {
      new TaskQueue($driver);
    } catch (RuntimeException $exception) {
      if (!str_contains($exception->getMessage(), 'task.store')) throw $exception;
      $rejected++;
    }
  }
  $manager = new QueueTestManager($file);
  $manager->register('demo', static fn(): int => 42);
  $nonQueue = $manager->accept(['topic' => 'demo', 'data' => null], $server);
  $wrappedRedis = new MonitoredDriver($store, 'redis');
  $queue = new TaskQueue($wrappedRedis);
  $data = $queue->persist(['topic' => 'demo', 'data' => 8], 'wrapped:0');
  $consumed = $queue->consume($data, '0');
  return ['rejected' => $rejected, 'nonQueue' => $nonQueue, 'hasFileWrites' => is_dir($path),
    'fileSupported' => TaskQueue::isSupported($wrappedFile),
    'redisSupported' => TaskQueue::isSupported($wrappedRedis), 'wrappedConsume' => $consumed];
}

/**
 * 历史空组和有陈旧成员的组都必须收敛，非空组必须保留。
 * @param QueueTestRedis $store 测试驱动
 * @return array 清理后的标签状态
 */
function observeGroupPruning(QueueTestRedis $store): array
{
  $emptyTag = TaskQueue::TAG_PREFIX . 'old-empty:0';
  $store->sAddArray($store->getTagStoreName(), $store->getTagKey($emptyTag));
  $queue = new TaskQueue($store);
  $pending = $queue->persist(['topic' => 'demo', 'data' => 9], 'pending:0');
  $queue->recover(static fn(array $data): int => 0);
  $tags = $store->getTags() ?: [];
  $observations = ['emptyRemoved' => !in_array($store->getTagKey($emptyTag), $tags, true),
    'pendingRegistered' => in_array($store->getTagKey(TaskQueue::TAG_PREFIX . 'pending:0'), $tags, true),
    'unrelatedRegistered' => in_array($store->getTagKey('unrelated'), $tags, true)];
  $queue->consume($pending, '0');
  return $observations;
}

/**
 * 多进程交错新增和回收同一分组，待消费成员仍必须能从注册入口发现。
 * @param QueueTestRedis $store 测试驱动
 * @param string $prefix 测试命名空间
 * @return array 竞争结束后的存活成员和空组回收结果
 */
function observePruningRace(QueueTestRedis $store, string $prefix): array
{
  $store->delete('ready');
  runContenders($prefix, static function (TaskQueue $queue, QueueTestRedis $store): void {
    for ($iteration = 0; $iteration < 20; $iteration++) {
      $data = $queue->persist(['topic' => 'demo', 'data' => $iteration], 'race:0');
      $store->pruneSet($store->getTagKey(TaskQueue::TAG_PREFIX . 'race:0'), $store->getTagStoreName(), 'gone');
      if ($iteration === 19) {
        $store->sAddArray('survivors', $data['queueId']);
        continue;
      }
      $queue->consume($data, '0');
    }
  });
  $tag = $store->getTagKey(TaskQueue::TAG_PREFIX . 'race:0');
  $survivors = $store->getArray('survivors') ?: [];
  $missing = array_diff($survivors, $store->getArray($tag) ?: []);
  $isRegistered = in_array($tag, $store->getTags() ?: [], true);
  $queue = new TaskQueue($store);
  foreach ($survivors as $queueId) $queue->consume($store->get($queueId), '0');
  return ['survivors' => count($survivors), 'missing' => count($missing), 'registered' => $isRegistered,
    'removedAfterConsume' => !in_array($tag, $store->getTags() ?: [], true)];
}

/**
 * 自定义日志回调抛错后，降级消息必须包含任务、原异常与日志异常，且仍能消费。
 * @param QueueTestRedis $store 测试驱动
 * @return array 消费与降级日志观测值
 */
function observeFailingCleanupLogger(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store, 30, static function (string $message, Throwable $exception): void {
    throw new RuntimeException('injected logger failure');
  });
  $data = $queue->persist(['topic' => 'demo', 'data' => 11], 'logging:0');
  $logPath = tempnam(sys_get_temp_dir(), 'queue-fallback-');
  $previousLog = ini_set('error_log', $logPath);
  set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, severity: $severity);
  });
  $store->hasPruneFailure = true;
  try {
    $consumed = $queue->consume($data, '0');
    $duplicate = $queue->consume($data, '0');
  } finally {
    $store->hasPruneFailure = false;
    restore_error_handler();
    ini_set('error_log', $previousLog);
  }
  $log = file_get_contents($logPath);
  unlink($logPath);
  $queue->recover(static fn(array $data): int => 0);
  return ['consumed' => $consumed, 'duplicate' => $duplicate,
    'hasMessage' => str_contains($log, '任务索引清理失败'), 'hasQueueId' => str_contains($log, $data['queueId']),
    'hasOriginalError' => str_contains($log, 'injected prune failure'),
    'hasLoggerError' => str_contains($log, 'injected logger failure')];
}
