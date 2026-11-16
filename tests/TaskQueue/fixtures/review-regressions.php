<?php
/**
 * 职责：运行 ACK 续租竞争、对象载荷和配置初始化的真实 Redis 回归；仅操作随机测试键。
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reliable-support.php';
require __DIR__ . '/review-support.php';

use Viswoole\Core\Server\Task\TaskFailedQueue;
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 在读取状态后、执行脚本前交错一次续租
 * @param QueueTestRedis $store 隔离商店
 * @param TaskQueue $queue 测试队列
 * @param array $lease 当前执行租约
 * @return void
 */
function interleaveRenewal(QueueTestRedis $store, TaskQueue $queue, array $lease): void
{
  $store->beforeUpdate = static function () use ($queue, $lease): void {
    if (!$queue->renew($lease)) throw new RuntimeException('交错续租必须成功');
  };
}

/**
 * 验证同一令牌的续租不会打断 ACK、失败或另一轮续租
 * @param QueueTestRedis $store 隔离商店
 * @return array 竞争观测
 */
function observeOwnerRaces(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store);
  $results = [];
  foreach (['ack', 'fail', 'renew'] as $operation) {
    $data = $queue->persist(['topic' => 'demo'], 'review-race:0');
    $lease = $queue->consume($data);
    interleaveRenewal($store, $queue, $lease);
    $results[$operation . 'DuringRenewal'] = $operation === 'fail'
      ? $queue->fail($lease, 'handler failure') : $queue->{$operation}($lease);
    $state = $store->get($data['queueId']);
    $results[$operation . 'State'] = match ($operation) {
      'ack' => $state === null,
      'fail' => $state['queueState'] === 'retry',
      'renew' => $state['queueState'] === 'running' && $queue->ack($lease),
    };
  }
  return $results;
}

/**
 * 验证原始对象字节跨越消费、重试和重放保持不变
 * @param QueueTestRedis $store 隔离商店
 * @return array 对象载荷观测
 */
function observeObjectPayload(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store);
  $data = $queue->persist(['topic' => 'demo', 'data' => new QueueWakePayload()], 'review-object:0');
  $lease = $queue->consume($data);
  $results['objectConsumed'] = $lease !== null;
  if ($lease === null) return $results;
  $queue->renew($lease);
  $results['objectBytesPreserved'] = $store->get($data['queueId'])['data']->wakeCount === 1;
  $queue->fail($lease, 'terminal', true);
  $failed = new TaskFailedQueue($store);
  $newId = $failed->retry($data['queueId']);
  $next = deliver($queue, $newId);
  $results['objectReplayed'] = $queue->ack($queue->consume($next));
  return $results;
}

/**
 * 验证初始化失败持续拒绝非法配置，修正后可正常缓存实例
 * @param QueueTestRedis $store 隔离商店
 * @return array 配置初始化观测
 */
function observeRetryInitialization(QueueTestRedis $store): array
{
  $config = new ReviewRetryConfig(['max_attempts' => 1, 'base_seconds' => 0]);
  $manager = new ReviewRetryManager($store, $config);
  $rejections = 0;
  foreach ([1, 2] as $attempt) {
    try {
      $manager->resolveQueue();
    } catch (InvalidArgumentException $exception) {
      $rejections++;
    }
  }
  $config->retry = ['max_attempts' => 2, 'base_seconds' => 1];
  $queue = $manager->resolveQueue();
  $data = $queue->persist(['topic' => 'demo'], 'review-config:0');
  $queue->fail($queue->consume($data), 'retryable');
  return ['invalidConfigRejectedTwice' => $rejections === 2,
    'correctedConfigCached' => $manager->resolveQueue() === $queue,
    'correctedPolicyApplied' => $store->get($data['queueId'])['queueState'] === 'retry'];
}

/**
 * 验证直接重新配置失败不会部分覆盖现有策略
 * @param QueueTestRedis $store 隔离商店
 * @return array 原子配置观测
 */
function observeRetryAtomicity(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store);
  $queue->configureRetry(['max_attempts' => 2]);
  try {
    $queue->configureRetry(['max_attempts' => 1, 'base_seconds' => 0]);
    return ['retryConfigAtomic' => false];
  } catch (InvalidArgumentException $exception) {
    $data = $queue->persist(['topic' => 'demo'], 'review-policy:0');
    $queue->fail($queue->consume($data), 'first failure');
    return ['retryConfigAtomic' => $store->get($data['queueId'])['queueState'] === 'retry'];
  }
}

/**
 * 验证新信封保留二进制、空值和自定义序列化的消费契约
 * @param QueueTestRedis $store 隔离商店
 * @return array 载荷格式观测
 */
function observePayloadFormats(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store);
  $isPreserved = true;
  foreach ([null, false, '', 0, 1.5, "\xff\0", ['value' => '42']] as $value) {
    $data = $queue->persist(['topic' => 'demo', 'data' => $value], 'review-format:0');
    $lease = $queue->consume($data);
    $isPreserved = $isPreserved && $store->get($data['queueId'])['data'] === $value && $queue->ack($lease);
  }
  $store->setSerialize('json_encode', static fn(string $value): mixed => json_decode($value, true));
  try {
    $data = $queue->persist(['topic' => 'demo', 'data' => ['value' => '42']], 'review-json:0');
    $lease = $queue->consume($data);
    $isJsonPreserved = $store->get($data['queueId'])['data'] === ['value' => '42'] && $queue->ack($lease);
  } finally {
    $store->setSerialize();
  }
  return ['payloadFormatsPreserved' => $isPreserved, 'customSerializationPreserved' => $isJsonPreserved];
}

/**
 * 验证严格版本竞争与记录删除后不会由迟到更新复活任务
 * @param QueueTestRedis $store 隔离商店
 * @return array 版本边界观测
 */
function observeRevisionBoundaries(QueueTestRedis $store): array
{
  $queue = new TaskQueue($store);
  $data = $queue->persist(['topic' => 'demo'], 'review-revision:0');
  $lease = $queue->consume($data);
  $before = $store->get($data['queueId']);
  $queue->renew($lease);
  $isStaleRejected = !$store->updateQueue($data['queueId'], ['expected' => $before, 'value' => null]);
  $queue->ack($lease);
  $isMissingRejected = !$store->updateQueue($data['queueId'], ['expected' => $before, 'value' => $before]);
  return ['strictRevisionRejected' => $isStaleRejected,
    'deletedTaskNotRecreated' => $isMissingRejected && !$store->has($data['queueId'])];
}

$prefix = 'queue-review-test:' . bin2hex(random_bytes(8)) . ':';
$store = new QueueTestRedis($prefix);
try {
  $results = observeOwnerRaces($store) + observeObjectPayload($store)
    + observeRetryInitialization($store) + observeRetryAtomicity($store)
    + observePayloadFormats($store) + observeRevisionBoundaries($store);
  echo json_encode($results, JSON_THROW_ON_ERROR);
} finally {
  $keys = $store->connect()->keys($prefix . '*');
  if ($keys !== []) $store->connect()->del($keys);
}
