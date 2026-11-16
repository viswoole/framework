<?php

declare(strict_types=1);

/* 职责：执行真实 Redis 行为场景，将观测结果交给 Vitest 断言。 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/regressions.php';

use Viswoole\Core\Server\TaskManager;
use Viswoole\Core\Server\TaskQueue;

$prefix = 'queue-test:' . bin2hex(random_bytes(8)) . ':';
$store = new QueueTestRedis($prefix);
$queue = new TaskQueue($store);
$results = [];

/**
 * 通过 Redis 自身的过期命令推进租约，不用等待真实的 30 秒。
 * @param QueueTestRedis $store 隔离驱动
 * @param array $data 持久化任务
 * @return void
 */
function expireClaim(QueueTestRedis $store, array $data): void
{
  $store->connect()->pExpire($store->getCacheKey('$_TASK_CLAIM_' . $data['queueId']), 1);
  usleep(5000);
}

/**
 * 同时运行多个独立进程，用 Redis 屏障确保竞争窗口重叠。
 * @param string $prefix 当前测试命名空间
 * @param Closure $action 子进程执行的队列操作
 * @return void
 */
function runContenders(string $prefix, Closure $action): void
{
  $processes = [];
  for ($index = 0; $index < 8; $index++) {
    $process = new Swoole\Process(static function () use ($prefix, $action): void {
      $childStore = new QueueTestRedis($prefix);
      $childStore->inc('ready');
      $deadline = microtime(true) + 5;
      while ((int)$childStore->get('ready') < 8) {
        if (microtime(true) > $deadline) exit(2);
        usleep(1000);
      }
      $action(new TaskQueue($childStore), $childStore);
      exit(0);
    });
    if ($process->start() === false) throw new RuntimeException('无法创建竞争测试进程');
    $processes[] = $process;
  }
  foreach ($processes as $process) {
    $status = Swoole\Process::wait(true);
    if ($status === false || $status['code'] !== 0 || $status['signal'] !== 0) {
      throw new RuntimeException('竞争测试子进程执行失败');
    }
  }
}

// 两个实例相同的本地 Worker ID 必须持久化到不同分组。
$first = $queue->persist(['topic' => 'demo', 'data' => 1], 'instance-a:0');
$second = $queue->persist(['topic' => 'demo', 'data' => 2], 'instance-b:0');
$results['groups'] = [
  'first' => $store->tag(TaskManager::CACHE_TAG_PREFIX . 'instance-a:0')->get(),
  'second' => $store->tag(TaskManager::CACHE_TAG_PREFIX . 'instance-b:0')->get(),
  'firstId' => $first['queueId'],
  'secondId' => $second['queueId'],
];
$results['initialLease'] = $queue->dispatch($first['queueId'], static fn(array $data): int => 99);

// 首个持久化实例崩溃后，租约到期，8 个恢复者中只有一个可以投递。
expireClaim($store, $first);
runContenders($prefix, static function (TaskQueue $queue, QueueTestRedis $store) use ($first): void {
  $queue->dispatch($first['queueId'], static function (array $data) use ($store): int {
    $store->inc('dispatches');
    return 0; // Swoole 任务 ID 0 是成功。
  });
});
$results['dispatches'] = $store->get('dispatches');
$results['retainedBeforeConsume'] = $store->has($first['queueId']);

// 租约再次到期导致旧、新投递并存时，8 个消费者中只有一个进入业务处理。
$store->delete('ready');
expireClaim($store, $first);
runContenders($prefix, static function (TaskQueue $queue, QueueTestRedis $store) use ($first): void {
  if ($queue->consume($first, '7')) $store->inc('handlers');
});
$results['handlers'] = $store->get('handlers');
$results['firstGroupAfterConsume'] = $store->tag(TaskManager::CACHE_TAG_PREFIX . 'instance-a:0')->get();
$results['otherGroupAfterConsume'] = $store->tag(TaskManager::CACHE_TAG_PREFIX . 'instance-b:0')->get();
$results['lateDispatch'] = $queue->dispatch($first['queueId'], static fn(array $data): int => 15);

// 投递失败后数据保留，下一次租约到期可恢复，不能永久锁死。
expireClaim($store, $second);
$results['failedDispatch'] = $queue->dispatch($second['queueId'], static fn(array $data): false => false);
$results['retainedAfterFailure'] = $store->has($second['queueId']);
expireClaim($store, $second);
$results['retryDispatch'] = $queue->dispatch($second['queueId'], static fn(array $data): int => 0);

// 旧 Worker 5 分组由新 Worker 0 恢复时，仍清理旧分组。
$legacy = ['queueId' => '5_legacy', 'topic' => 'demo', 'data' => 3];
$store->tag(TaskManager::CACHE_TAG_PREFIX . '5')->set($legacy['queueId'], $legacy, 0);
$store->tag('unrelated')->set('foreign-key', ['topic' => 'other'], 0);
$recovered = [];
$queue->recover(static function (array $data) use (&$recovered, $queue): int {
  $recovered[] = $data['queueGroup'];
  $queue->consume($data, '0');
  return 12;
});
$results['legacy'] = [
  'groups' => $recovered,
  'members' => $store->tag(TaskManager::CACHE_TAG_PREFIX . '5')->get(),
  'foreignExists' => $store->has('foreign-key'),
];

// 崩溃遗留成员和空分组应全部回收。
$store->tag(TaskManager::CACHE_TAG_PREFIX . 'gone:8')->push('missing-id');
$queue->recover(static fn(array $data): int => 1);
$results['staleMembers'] = $store->tag(TaskManager::CACHE_TAG_PREFIX . 'gone:8')->get();
$results['registeredGroup'] = in_array($store->getTagKey(TaskManager::CACHE_TAG_PREFIX . 'instance-a:0'), $store->getTags(), true);

// 真实 onTask 入口保留处理器返回值，拦截重复任务且不影响非队列任务。
$manager = new QueueTestManager($store);
$server = new Swoole\Server('127.0.0.1', 0, SWOOLE_BASE);
$handlerCalls = 0;
$manager->register('demo', static function () use (&$handlerCalls): array {
  $handlerCalls++;
  return ['ok' => true];
});
$entry = $queue->persist(['topic' => 'demo', 'data' => 4], 'entry:0');
$results['entry'] = [
  'first' => $manager->accept($entry, $server),
  'second' => $manager->accept($entry, $server),
  'calls' => $handlerCalls,
];
$results['nonQueue'] = $manager->accept(['topic' => 'demo', 'data' => 5], $server);
$results['nonQueueCalls'] = $handlerCalls;
$discarded = $queue->persist(['topic' => 'demo', 'data' => 6], 'discard:0');
$queue->discard($discarded);
$results['discard'] = [
  'exists' => $store->has($discarded['queueId']),
  'members' => $store->tag(TaskManager::CACHE_TAG_PREFIX . 'discard:0')->get(),
];

$results['cleanupRecovery'] = observeCleanupRecovery($store, $server);
$results['unknownTopic'] = observeUnknownTopicCleanup($store, $server);
$results['capabilities'] = observeStoreCapabilities($store, $server);
$results['groupPruning'] = observeGroupPruning($store);
$results['pruningRace'] = observePruningRace($store, $prefix);
$results['failingCleanupLogger'] = observeFailingCleanupLogger($store);

// 只删除本次测试的随机命名空间，不触碰同库其他使用者。
$keys = $store->connect()->keys($prefix . '*');
if ($keys !== []) $store->connect()->del($keys);
echo json_encode($results, JSON_THROW_ON_ERROR);
