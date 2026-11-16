<?php
/**
 * 职责：构造到期损坏记录及隔离交错；使用真实 Redis 和生产脚本。
 */
declare(strict_types=1);

use Viswoole\Cache\Contract\QueueStoreInterface;
use Viswoole\Cache\Driver\Redis\QueueRecord;
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 创建仍有分组与到期入口的损坏记录，保留损坏前快照以模拟并发修复。
 * @param QueueTestRedis $store 商店
 * @param string $group 测试分组
 * @return array{id: string, valid: string, broken: string} 原始快照
 * @throws Throwable 场景存储或并发操作失败时抛出
 */
function createBrokenTask(QueueTestRedis $store, string $group): array
{
    $queue = new TaskQueue($store);
    $data = $queue->persist(['topic' => 'demo'], $group);
    $before = $store->getQueueMetadata($data['queueId']);
    $after = $before;
    $after['leaseUntil'] = 0;
    if (!$store->updateQueue($data['queueId'], ['expected' => $before, 'value' => $after])) {
        throw new RuntimeException('推进测试租约失败');
    }
    $key = $store->getCacheKey($data['queueId']);
    $valid = $store->connect()->get($key);
    $broken = QueueRecord::PREFIX . "{invalid\xff\0";
    $store->connect()->set($key, $broken);
    return ['id' => $data['queueId'], 'valid' => $valid, 'broken' => $broken];
}

/**
 * 回拨仅用于测试的投递期限，保持索引由生产脚本同步维护。
 * @param QueueTestRedis $store 商店
 * @param string $id 任务标识
 * @return void
 * @throws Throwable 场景存储或并发操作失败时抛出
 */
function expireIsolationDelivery(QueueTestRedis $store, string $id): void
{
    $before = $store->getQueueMetadata($id);
    $after = $before;
    $after['leaseUntil'] = 1;
    if (!$store->updateQueue($id, ['expected' => $before, 'value' => $after])) {
        throw new RuntimeException('推进投递租约失败');
    }
}

/**
 * 损坏记录占满首批时，下一轮必须能处理之后的健康任务。
 * @param QueueTestRedis $store 独立商店
 * @return array 饥饿修复与目录清理观测
 * @throws Throwable 场景存储或并发操作失败时抛出
 */
function observeFullPoisonBatch(QueueTestRedis $store): array
{
    $first = createBrokenTask($store, 'poison:0');
    for ($index = 1; $index < 100; $index++) createBrokenTask($store, 'poison:0');
    $queue = new TaskQueue($store);
    $healthy = $queue->persist(['topic' => 'demo'], 'healthy:0');
    expireIsolationDelivery($store, $healthy['queueId']);
    $calls = 0;
    $dispatch = static function (array $data) use ($queue, &$calls): int {
        if (!$queue->ack($queue->consume($data))) throw new RuntimeException('健康任务未被确认');
        return ++$calls;
    };
    $queue->recover($dispatch);
    $results['firstBatchQuarantined'] = $calls === 0 && count($queue->quarantined()->list(1, 100)) === 100;
    $queue->recover($dispatch);
    $record = $queue->quarantined()->get($first['id']);
    $results['healthyAfterPoisonBatch'] = $calls === 1;
    $results['rawBytesPreserved'] = $record['raw'] === $first['broken'] && $record['reason'] !== '';
    $results['poisonGroupPruned'] = ($store->getArray($store->getTagKey(TaskQueue::TAG_PREFIX . 'poison:0')) ?: []) === [];
    $results['directoryPruned'] = $store->connect()->hLen($store->getCacheKey(QueueStoreInterface::DIRECTORY_KEY)) === 0;
    return $results;
}

/**
 * 隔离前原记录已被修复，不能误删新的正常版本。
 * @param QueueTestRedis $store 商店
 * @return array 并发修复观测
 * @throws Throwable 场景存储或并发操作失败时抛出
 */
function observeConcurrentRepair(QueueTestRedis $store): array
{
    $snapshots = createBrokenTask($store, 'repair:0');
    $store->beforeQuarantine = static function () use ($store, $snapshots): void {
        $store->connect()->set($store->getCacheKey($snapshots['id']), $snapshots['valid']);
    };
    $queue = new TaskQueue($store);
    $calls = 0;
    $dispatch = static function (array $data) use ($queue, &$calls): int {
        $queue->ack($queue->consume($data));
        return ++$calls;
    };
    $queue->recover($dispatch);
    $results['repairedRecordNotQuarantined'] = $store->has($snapshots['id'])
        && $queue->quarantined()->get($snapshots['id']) === null;
    $queue->recover($dispatch);
    $results['repairedRecordRecovered'] = $calls === 1;
    return $results;
}

/**
 * 隔离写入失败必须保留数据，且不能阻止同批其他任务。
 * @param QueueTestRedis $store 商店
 * @return array 故障保留观测
 * @throws Throwable 场景存储或并发操作失败时抛出
 */
function observeQuarantineFailure(QueueTestRedis $store): array
{
    $snapshots = createBrokenTask($store, 'failure:0');
    $queue = new TaskQueue($store);
    $healthy = $queue->persist(['topic' => 'demo'], 'failure-healthy:0');
    expireIsolationDelivery($store, $healthy['queueId']);
    $store->hasQuarantineFailure = true;
    $calls = 0;
    $queue->recover(static function (array $data) use ($queue, &$calls): int {
        $queue->ack($queue->consume($data));
        return ++$calls;
    });
    $results['failedQuarantineRetainsRaw'] = $store->connect()->get($store->getCacheKey($snapshots['id'])) === $snapshots['broken'];
    $results['failedQuarantineAllowsHealthy'] = $calls === 1;
    $store->hasQuarantineFailure = false;
    $queue->recover(static fn(): int => 0);
    $results['quarantineRetrySucceeds'] = $queue->quarantined()->get($snapshots['id']) !== null;
    return $results;
}

/**
 * JSON 商店也必须保留任意二进制快照及原因。
 * @param QueueTestRedis $store 商店
 * @return array 编码边界观测
 * @throws Throwable 场景存储或并发操作失败时抛出
 */
function observeBinaryQuarantine(QueueTestRedis $store): array
{
    $snapshots = createBrokenTask($store, 'json-quarantine:0');
    $store->setSerialize('json_encode', static fn(string $value): mixed => json_decode($value, true));
    try {
        $reason = "invalid\xff\0reason";
        $isQuarantined = $store->quarantineQueue($snapshots['id'], $snapshots['broken'], $reason);
        $record = (new TaskQueue($store))->quarantined()->get($snapshots['id']);
        return ['jsonQuarantinePreservesBinary' => $isQuarantined
            && $record['raw'] === $snapshots['broken'] && $record['reason'] === $reason];
    } finally {
        $store->setSerialize();
    }
}

/**
 * 多个独立连接竞争同一快照，必须只有一个隔离成功。
 * @param string $prefix 隔离命名空间
 * @param string $id 任务标识
 * @param string $snapshot 损坏字节
 * @return void
 * @throws Throwable 场景存储或并发操作失败时抛出
 */
function runQuarantineContenders(string $prefix, string $id, string $snapshot): void
{
    $processes = [];
    for ($index = 0; $index < 4; $index++) {
        $process = new Swoole\Process(static function () use ($prefix, $id, $snapshot): void {
            $store = new QueueTestRedis($prefix);
            $store->inc('quarantine-ready');
            $deadline = microtime(true) + 5;
            while ((int)$store->get('quarantine-ready') < 4) {
                if (microtime(true) > $deadline) exit(2);
                usleep(1000);
            }
            if ($store->quarantineQueue($id, $snapshot, 'concurrent quarantine')) $store->inc('quarantine-winners');
            exit(0);
        });
        if ($process->start() === false) throw new RuntimeException('创建隔离竞争进程失败');
        $processes[] = $process;
    }
    foreach ($processes as $process) {
        $status = Swoole\Process::wait(true);
        if ($status === false || $status['code'] !== 0 || $status['signal'] !== 0) {
            throw new RuntimeException('隔离竞争进程执行失败');
        }
    }
}

/**
 * JSON 可解析但元数据类型已损坏时，同样必须隔离。
 * @param QueueTestRedis $store 商店
 * @return array 元数据校验观测
 * @throws Throwable 存储失败时抛出
 */
function observeInvalidMetadata(QueueTestRedis $store): array
{
    $snapshots = createBrokenTask($store, 'metadata:0');
    $envelope = json_decode(substr($snapshots['valid'], strlen(QueueRecord::PREFIX)), true, flags: JSON_THROW_ON_ERROR);
    $envelope['metadata']['leaseUntil'] = 'invalid-time';
    $broken = QueueRecord::PREFIX . json_encode($envelope, JSON_THROW_ON_ERROR);
    $store->connect()->set($store->getCacheKey($snapshots['id']), $broken);
    $queue = new TaskQueue($store);
    $queue->recover(static fn(): int => 0);
    $record = $queue->quarantined()->get($snapshots['id']);
    return ['invalidMetadataQuarantined' => $record !== null && $record['raw'] === $broken];
}

/**
 * 任务数据缺失时也必须原子回收分组、目录和调度入口。
 * @param QueueTestRedis $store 商店
 * @return array 缺失记录清理观测
 * @throws Throwable 存储操作失败时抛出
 */
function observeMissingRecordCleanup(QueueTestRedis $store): array
{
    $queue = new TaskQueue($store);
    $data = $queue->persist(['topic' => 'demo'], 'missing:0');
    expireIsolationDelivery($store, $data['queueId']);
    $store->delete($data['queueId']);
    $queue->recover(static fn(): int => 0);
    $directory = $store->getCacheKey(QueueStoreInterface::DIRECTORY_KEY);
    $schedule = $store->getCacheKey(QueueStoreInterface::SCHEDULE_KEY);
    return ['missingRecordIndexesPruned' => $store->connect()->hGet($directory, serialize($data['queueId'])) === false
        && $store->connect()->zScore($schedule, serialize($data['queueId'])) === false
        && ($store->getArray($store->getTagKey(TaskQueue::TAG_PREFIX . 'missing:0')) ?: []) === [],
        'missingRecordNotQuarantined' => $queue->quarantined()->get($data['queueId']) === null];
}
