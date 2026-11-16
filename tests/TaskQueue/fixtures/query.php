<?php

/*
 * 职责：以随机 Redis 前缀验证任务查询、实例接管和索引回收，不读取业务凭证。
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Viswoole\Cache\MonitoredDriver;
use Viswoole\Core\Server\Task\TaskQueue;
use Viswoole\Core\Server\Task\TaskQueueQuery;
use Viswoole\Core\Server\Task\TaskQueueFilter;
use Viswoole\Core\Server\Task\TaskQueueState;
use Viswoole\Core\Server\Task\TaskFailedQueue;

/**
 * 查询不得恢复此对象。
 */
final class QueryPayload
{
  /**
   * @var int 当前进程对象恢复次数
   */
  public static int $wakeups = 0;
  /**
   * 记录业务对象恢复
   *
   * @return void 更新观测次数
   */
  public function __wakeup(): void { self::$wakeups++; }
}
/**
 * 推进测试租约，所有索引仍由生产脚本更新。
 *
 * @param QueueTestRedis $store 隔离商店
 * @param string $id 队列身份
 * @return void 完成租约推进
 */
function expireQueryTask(QueueTestRedis $store, string $id): void
{
  $before = $store->getQueueMetadata($id);
  $after = $before;
  $after['leaseUntil'] = $after['retryAt'] = 0;
  // 模拟消费者退出后的到期快照，避免同令牌续租的期限单调保护夹回旧期限。
  $after['queueToken'] = bin2hex(random_bytes(24));
  $store->updateQueue($id, ['expected' => $before, 'value' => $after]);
}
$prefix = 'task-query:' . bin2hex(random_bytes(8)) . ':';
$store = new QueueTestRedis($prefix);
$queue = new TaskQueue($store);
$query = new TaskQueueQuery($store);
$results = [];
try {
  $first = $queue->persist(['topic' => 'Sms.Send', 'data' => new QueryPayload()], 'producer:a:1');
  $second = $queue->persist(['topic' => 'demo', 'data' => new QueryPayload()], 'producer-b:2');
  $third = $queue->persist(['topic' => 'demo', 'data' => new QueryPayload()], 'producer-b:3');
  $lease = $queue->consume($first, 'executor-b', 8);
  $failedLease = $queue->consume($second, 'executor-c', 9);
  $queue->fail($failedLease, 'terminal failure', true);
  $filter = new TaskQueueFilter(['producerInstanceId' => 'producer:a']);
  $results['producer'] = count($query->list($filter)['list']) === 1;
  $filter = new TaskQueueFilter(['executionInstanceId' => 'executor-b']);
  $results['execution'] = count($query->list($filter)['list']) === 1;
  $filter = new TaskQueueFilter(['producerInstanceId' => 'producer:a', 'executionInstanceId' => 'executor-b',
    'topic' => 'sms.send', 'state' => TaskQueueState::Running]);
  $results['combined'] = count($query->list($filter)['list']) === 1;
  $counts = $query->count();
  $results['counts'] = $counts === ['total' => 3, 'dispatched' => 1, 'running' => 1, 'retry' => 0, 'failed' => 1, 'errors' => [], 'isComplete' => true]
    && $query->count($filter)['total'] === 1;
  $detail = $query->get($first['queueId']);
  $results['details'] = $detail['producerInstanceId'] === 'producer:a' && $detail['executionInstanceId'] === 'executor-b'
    && $detail['executionWorkerId'] === 8 && $detail['state'] === 'running';
  $results['metadataOnly'] = QueryPayload::$wakeups === 0 && array_intersect_key($detail, array_flip(['data', 'queuePayload', 'queueToken', 'queueRevision'])) === [];
  $keys = $store->connect()->keys($prefix . '*');
  sort($keys);
  $before = array_map(fn (string $key): string => $store->connect()->dump($key), $keys);
  $query->list();
  $query->count();
  $query->get($first['queueId']);
  $results['readOnly'] = $before === array_map(fn (string $key): string => $store->connect()->dump($key), $keys);
  $ids = [];
  $cursor = '';
  do {
    $page = $query->list(new TaskQueueFilter(), $cursor, 1);
    $cursor = $page['cursor'];
    foreach ($page['list'] as $record) { $ids[] = $record['queueId']; }
  } while ($cursor !== '');
  $results['pagination'] = count($ids) === 3 && count(array_unique($ids)) === 3;
  expireQueryTask($store, $first['queueId']);
  $results['expiredOwner'] = $query->get($first['queueId'])['executionInstanceId'] === null
    && $query->get($first['queueId'])['executionLeaseExpired'] && $query->count($filter)['total'] === 0;
  $queue->dispatch($first['queueId'], static fn (): int => 0);
  expireQueryTask($store, $first['queueId']);
  $queue->dispatch($first['queueId'], static fn (): int => 0);
  $newLease = $queue->consume($queue->delivery($first['queueId']), 'executor-new', 4);
  $results['crossInstance'] = $query->get($first['queueId'])['producerInstanceId'] === 'producer:a'
    && $query->get($first['queueId'])['executionInstanceId'] === 'executor-new';
  $queue->renew($newLease);
  $results['renewKeepsOwner'] = $query->get($first['queueId'])['executionInstanceId'] === 'executor-new';
  $results['failedCleared'] = $query->get($second['queueId'])['executionInstanceId'] === null;
  $failed = new TaskFailedQueue($store);
  $replayedId = $failed->retry($second['queueId']);
  $results['replayed'] = is_string($replayedId) && $query->get($second['queueId']) === null
    && $query->get($replayedId)['producerInstanceId'] === 'producer-b' && $query->get($replayedId)['executionInstanceId'] === null;
  $thirdLease = $queue->consume($third, 'executor-c', 3);
  $queue->ack($thirdLease);
  $results['ackRemoved'] = $query->get($third['queueId']) === null && $query->count()['total'] === 2;
  $delivery = $queue->persist(['topic' => 'delete'], 'p:1');
  $queue->fail($queue->consume($delivery, 'e', 1), 'delete', true);
  $failed->delete($delivery['queueId']);
  $results['deleteRemoved'] = $query->get($delivery['queueId']) === null;
  $delivery = $queue->persist(['topic' => 'broken'], 'p:1');
  $raw = $store->connect()->get($store->getCacheKey($delivery['queueId']));
  $store->quarantineQueue($delivery['queueId'], $raw, 'broken');
  $results['quarantineRemoved'] = $query->get($delivery['queueId']) === null;
  $delivery = $queue->persist(['topic' => 'missing'], 'p:1');
  $store->connect()->del($store->getCacheKey($delivery['queueId']));
  $store->pruneMissingQueue($delivery['queueId']);
  $results['missingRemoved'] = $query->get($delivery['queueId']) === null;
  $manager = new QueueTestManager($store);
  $manager->register('automatic', static function (\Viswoole\Core\Server\Task\TaskProxy $task) use ($manager): bool {
    return $manager->queue()->get($task->queue_id)['executionInstanceId'] === $manager->instanceId();
  });
  $automatic = $queue->persist(['topic' => 'automatic'], 'original-producer:0');
  $results['automaticOwner'] = $manager->accept($automatic, new Swoole\Server('127.0.0.1', 0, SWOOLE_BASE)) === true;
  $decorated = new TaskQueueQuery(new MonitoredDriver($store, 'query-test'));
  $results['decorator'] = $decorated->count() === $query->count();
  foreach (['invalidFilters' => fn () => new TaskQueueFilter(['state' => 'running']),
    'invalidCursor' => fn () => $query->list(new TaskQueueFilter(), 'invalid'),
    'invalidLimit' => fn () => $query->list(new TaskQueueFilter(), '', 101)] as $name => $action) {
    try { $action();
      $results[$name] = false; } catch (InvalidArgumentException) { $results[$name] = true; }
  }
  $results['unknownDetail'] = $query->get(str_repeat('f', 48)) === null;
  for ($index = 0; $index < 1005; $index++) { $queue->enqueue(['topic' => 'bulk'], 'bulk:0'); }
  $filter = new TaskQueueFilter(['topic' => 'absent']);
  $page = $query->list($filter);
  $results['boundedScan'] = $page['list'] === [] && $page['cursor'] !== '' && $query->count($filter)['total'] === 0
    && $query->count(new TaskQueueFilter(['topic' => 'bulk']))['total'] === 1005;
  echo json_encode($results, JSON_THROW_ON_ERROR);
} finally {
  $keys = $store->connect()->keys($prefix . '*');
  if ($keys !== []) { $store->connect()->del($keys); }
}
