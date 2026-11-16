<?php
/**
 * 职责：提供可靠队列故障注入和并发观测；不替代生产状态转换逻辑。
 */
declare(strict_types=1);
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 仅推进投递或退避等待，执行租约必须真实到期以保留单调续租约束。
 * @param QueueTestRedis $store 测试商店
 * @param string $id 任务 ID
 * @return void
 * @throws InvalidArgumentException 尝试回拨执行租约时抛出
 * @throws Throwable 存储读取或状态更新失败时抛出
 */
function makeDue(QueueTestRedis $store, string $id): void
{
  $before = $store->getQueueMetadata($id);
  if ($before['queueState'] === 'running') throw new InvalidArgumentException('执行租约必须通过真实时间过期');
  $after = $before;
  $after['leaseUntil'] = 0;
  $after['retryAt'] = 0;
  if (!$store->updateQueue($id, ['expected' => $before, 'value' => $after])) {
    throw new RuntimeException('推进测试任务时间失败：' . $id);
  }
}

/**
 * 投递任务并返回完整投递载荷
 * @param TaskQueue $queue 测试队列
 * @param string $id 任务 ID
 * @return array 完整投递载荷
 */
function deliver(TaskQueue $queue, string $id): array
{
  $payload = null;
  $queue->dispatch($id, static function (array $data) use (&$payload): int {
    $payload = $data;
    return 0;
  });
  return $payload ?? throw new RuntimeException('预期任务可投递：' . $id);
}

/**
 * 观测租约过期后的接管与失败转移
 * @param TaskQueue $queue 测试队列
 * @param QueueTestRedis $store 测试商店
 * @return array 恢复与令牌观测
 */
function observeRecovery(TaskQueue $queue, QueueTestRedis $store): array
{
  $data = $queue->persist(['topic' => 'demo'], 'crash:0');
  $old = $queue->consume($data);
  // 执行租约不能被测试脚本回拨，真实等待才可验证过期接管。
  usleep((int)ceil(max(0.0, $old['leaseUntil'] - microtime(true) + 0.05) * 1000000));
  $queue->dispatch($data['queueId'], static fn(): int => 0);
  $recovered = $store->get($data['queueId']);
  makeDue($store, $data['queueId']);
  $next = deliver($queue, $data['queueId']);
  $lease = $queue->consume($next);
  $result = ['crashRecovered' => $recovered['queueState'] === 'retry',
    'staleRejected' => !$queue->ack($old) && !$queue->renew($old) && !$queue->fail($old, 'late'),
    'newOwnerAck' => $queue->ack($lease)];
  $data = $queue->persist(['topic' => 'demo'], 'delivery:0');
  $queue->deliveryFailed($data, 'dispatch failure');
  $result['deliveryRetained'] = $store->get($data['queueId'])['attempts'] === 0;
  makeDue($store, $data['queueId']);
  try {
    $queue->dispatch($data['queueId'], static fn(): never => throw new RuntimeException('dispatch error'));
  } catch (RuntimeException $exception) {
    $result['deliveryException'] = $store->get($data['queueId'])['queueState'] === 'retry';
  }
  return $result;
}

/**
 * 观测真实 onTask 入口的各类消费路径
 * @param TaskQueue $queue 测试队列
 * @param QueueTestRedis $store 测试商店
 * @return array 真实 TaskManager 入口观测
 */
function observeConsumers(TaskQueue $queue, QueueTestRedis $store, Swoole\Server $server): array
{
  $manager = new QueueTestManager($store);
  $manager->register('demo', static fn(): bool => false);
  $data = $queue->persist(['topic' => 'demo'], 'entry:0');
  $result['falseAcked'] = $manager->accept($data, $server) === false && !$store->has($data['queueId']);
  $manager->register('demo', static fn(): never => throw new RuntimeException('handler failed'));
  $data = $queue->persist(['topic' => 'demo'], 'entry:0');
  $manager->accept($data, $server);
  $result['handlerRetried'] = $store->get($data['queueId'])['queueState'] === 'retry';
  $data = $queue->persist(['topic' => 'missing'], 'entry:0');
  $manager->accept($data, $server);
  $result['unknownFailed'] = $store->get($data['queueId'])['queueState'] === 'failed';
  $data = $queue->persist(['topic' => 'demo', 'expire' => 1, 'dispatch_time' => microtime(true) - 2], 'entry:0');
  $manager->accept($data, $server);
  $result['expiredFailed'] = $store->get($data['queueId'])['queueState'] === 'failed';
  $manager->register('demo', static fn(\Viswoole\Core\Server\Task\TaskProxy $task): bool => $task->renewLease());
  $data = $queue->persist(['topic' => 'demo'], 'entry:0');
  $result['manualRenew'] = $manager->accept($data, $server) === true;
  $result['nonQueue'] = $manager->accept(['topic' => 'demo'], $server) === false;
  $manager->register('demo', static fn(): bool => true);
  $result['nonQueueExpiry'] = $manager->accept([
    'topic' => 'demo', 'expire' => 1, 'dispatch_time' => microtime(true) - 2,
  ], $server) === false;
  return $result;
}

/**
 * 八个独立连接竞争同一条投递，验证原子执行权。
 * @param string $prefix 隔离键前缀
 * @param TaskQueue $queue 测试队列
 * @param QueueTestRedis $store 测试商店
 * @return array 并发结果
 */
function observeCompetition(string $prefix, TaskQueue $queue, QueueTestRedis $store): array
{
  $data = $queue->persist(['topic' => 'demo'], 'race:0');
  $processes = [];
  for ($index = 0; $index < 8; $index++) {
    $process = new Swoole\Process(static function () use ($prefix, $data): void {
      $child = new QueueTestRedis($prefix);
      $queue = new TaskQueue($child);
      $child->inc('ready');
      $deadline = microtime(true) + 5;
      while ((int)$child->get('ready') < 8) {
        if (microtime(true) > $deadline) exit(2);
        usleep(1000);
      }
      $lease = $queue->consume($data);
      if ($lease !== null) $child->inc('winners');
      exit(0);
    });
    if ($process->start() === false) throw new RuntimeException('创建竞争进程失败');
    $processes[] = $process;
  }
  foreach ($processes as $process) {
    $status = Swoole\Process::wait(true);
    if ($status === false || $status['code'] !== 0) throw new RuntimeException('竞争进程失败');
  }
  return ['competition' => (int)$store->get('winners')];
}
