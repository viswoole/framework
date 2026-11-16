<?php

/*
 * 职责：用真实 Redis 注入索引损坏，仅清理随机测试前缀，不修改任务主记录。
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Viswoole\Core\Server\Task\TaskQueue;
use Viswoole\Core\Server\Task\TaskQueueQuery;
use Viswoole\Core\Server\Task\TaskQueueFilter;
use Viswoole\Cache\Contract\QueueStoreInterface;

$prefix = 'query-corruption:' . bin2hex(random_bytes(8)) . ':';
$store = new QueueTestRedis($prefix);
try {
  $queue = new TaskQueue($store);
  $query = new TaskQueueQuery($store);
  $ids = [];
  for ($index = 0; $index < 10; $index++) { $ids[] = $queue->enqueue(['topic' => 'demo'], 'producer:0'); }
  sort($ids);
  $key = $store->getCacheKey(QueueStoreInterface::QUERY_METADATA_KEY);
  $metadata = $store->getQueueMetadata($ids[0]);
  $variants = ['invalidJson' => '{broken', 'scalar' => '42', 'null' => 'null',
    'nonFinite' => str_replace('"attempts":0', '"attempts":NaN', json_encode($metadata)),
    'state' => json_encode([...$metadata, 'queueState' => 'invalid']),
    'lease' => json_encode([...$metadata, 'queueState' => 'running', 'leaseUntil' => 'invalid']),
    'errorEncoding' => json_encode([...$metadata, 'lastErrorBase64' => '!invalid!']),
    'identity' => json_encode([...$metadata, 'queueId' => $ids[9]])];
  $results = [];
  foreach ($variants as $name => $raw) {
    $store->connect()->hSet($key, serialize($ids[0]), $raw);
    try {
      $page = $query->list();
      $results[$name] = count($page['list']) === 9 && !$page['isComplete']
        && $page['errors'][0]['queueId'] === $ids[0];
    } catch (Throwable) { $results[$name] = false; }
  }
  $store->connect()->hDel($key, serialize($ids[0]));
  try {
    $page = $query->list();
    $results['missing'] = !$page['isComplete'] && $page['errors'][0]['queueId'] === $ids[0];
  } catch (Throwable) { $results['missing'] = false; }
  try { $query->get($ids[0]);
    $results['missingDetail'] = false; } catch (Throwable $exception) { $results['missingDetail'] = str_contains($exception->getMessage(), $ids[0]); }
  $store->connect()->hSet($key, serialize($ids[0]), '{broken');
  $keys = $store->connect()->keys($prefix . '*');
  sort($keys);
  $before = array_map(fn (string $key): string => $store->connect()->dump($key), $keys);
  try {
    $page = $query->list(new TaskQueueFilter(), '', 1);
    $results['healthyListed'] = count($page['list']) === 1 && $page['list'][0]['queueId'] === $ids[1] && !$page['isComplete'];
    $count = $query->count();
    $results['countIncomplete'] = $count['total'] === 9 && !$count['isComplete'] && $count['errors'][0]['queueId'] === $ids[0];
    $next = $query->list(new TaskQueueFilter(), $page['cursor'], 1);
    $results['cursorContinues'] = $next['list'][0]['queueId'] === $ids[2] && $next['isComplete'];
    $count = $query->count(new TaskQueueFilter(['topic' => 'absent']));
    $results['filteredIncomplete'] = $count['total'] === 0 && !$count['isComplete'] && count($count['errors']) === 1;
  } catch (Throwable) {
    foreach (['healthyListed', 'countIncomplete', 'cursorContinues', 'filteredIncomplete'] as $name) { $results[$name] = false; }
  }
  try { $query->get($ids[0]);
    $results['detailIdentified'] = false; } catch (Throwable $exception) { $results['detailIdentified'] = str_contains($exception->getMessage(), $ids[0]); }
  $results['readOnly'] = $before === array_map(fn (string $key): string => $store->connect()->dump($key), $keys);
  echo json_encode($results, JSON_THROW_ON_ERROR);
} finally {
  $keys = $store->connect()->keys($prefix . '*');
  if ($keys !== []) { $store->connect()->del($keys); }
}
