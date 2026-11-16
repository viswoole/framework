<?php
/**
 * 职责：验证到期索引、恢复批量上限和元数据操作；仅清理随机测试键。
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Viswoole\Core\Server\Task\TaskQueue;
use Viswoole\Core\Server\Task\TaskFailedQueue;

/**
 * 用对象恢复计数验证状态操作不会反序列化业务数据。
 */
final class IndexedPayload
{
    /**
     * @var int 当前进程业务对象恢复次数
     */
    public static int $restorations = 0;

    /**
     * 记录一次业务对象恢复。
     * @return void
     */
    public function __wakeup(): void
    {
        self::$restorations++;
    }
}

/**
 * 提取 Redis 命令次数，不对其他连接执行 RESETSTAT。
 * @param array $stats 命令统计
 * @param string $command Redis 命令名称
 * @return int 调用次数
 */
function countCommand(array $stats, string $command): int
{
    preg_match('/calls=(\d+)/', $stats['cmdstat_' . $command] ?? '', $matches);
    return (int)($matches[1] ?? 0);
}

/**
 * 空扫描不检查历史分组，也不读取未到期任务载荷。
 * @param QueueTestRedis $store 隔离商店
 * @return array 性能观测
 */
function observeIdleIndex(QueueTestRedis $store): array
{
    $queue = new TaskQueue($store);
    for ($index = 0; $index < 100; $index++) {
        $queue->persist(['topic' => 'demo', 'data' => str_repeat('x', 8192)], 'idle:0');
    }
    $before = $store->connect()->info('commandstats');
    $dispatches = 0;
    $dispatch = static function () use (&$dispatches): int { return ++$dispatches; };
    $queue->recover($dispatch);
    $queue->recover($dispatch);
    $after = $store->connect()->info('commandstats');
    return ['idleNoPayloadReads' => countCommand($after, 'get') === countCommand($before, 'get'),
        'idleNoExistenceReads' => countCommand($after, 'exists') === countCommand($before, 'exists'),
        'idleNoDispatch' => $dispatches === 0];
}

/**
 * 强制已持有的任务到期时也通过生产原子提交更新调度索引。
 * @param QueueTestRedis $store 商店
 * @param string $id 任务标识
 * @return void
 */
function makeIndexedDue(QueueTestRedis $store, string $id): void
{
    $before = $store->getQueueMetadata($id);
    $after = $before;
    $after['leaseUntil'] = 0;
    $after['retryAt'] = 0;
    if (!$store->updateQueue($id, ['expected' => $before, 'value' => $after])) {
        throw new RuntimeException('推进测试租约失败');
    }
}

/**
 * 验证每轮只恢复固定数量，后续轮次能继续处理剩余任务。
 * @param QueueTestRedis $store 隔离商店
 * @return array 分批恢复结果
 */
function observeBatchLimit(QueueTestRedis $store): array
{
    $queue = new TaskQueue($store);
    for ($index = 0; $index < 150; $index++) {
        $data = $queue->persist(['topic' => 'demo'], 'batch:0');
        makeIndexedDue($store, $data['queueId']);
    }
    $dispatches = 0;
    $dispatch = static function (array $data) use ($queue, &$dispatches): int {
        if (!$queue->ack($queue->consume($data))) throw new RuntimeException('分批消费失败');
        return ++$dispatches;
    };
    $queue->recover($dispatch);
    $results['firstBatchBounded'] = $dispatches === 100;
    $queue->recover($dispatch);
    $results['remainingRecovered'] = $dispatches === 150;
    return $results;
}

/**
 * 验证 ACK、续租、失败和重放与到期集合保持一致。
 * @param QueueTestRedis $store 隔离商店
 * @return array 调度一致性观测
 */
function observeScheduleStates(QueueTestRedis $store): array
{
    $queue = new TaskQueue($store);
    $data = $queue->persist(['topic' => 'demo', 'data' => new IndexedPayload()], 'states:0');
    $lease = $queue->consume($data);
    $queue->renew($lease);
    $results['metadataDoesNotRestoreObjects'] = IndexedPayload::$restorations === 0;
    $key = $store->getCacheKey('$_TASK_SCHEDULE_');
    $score = $store->connect()->zScore($key, serialize($data['queueId']));
    $results['renewalMovesSchedule'] = abs($score - $store->getQueueMetadata($data['queueId'])['leaseUntil']) < 0.001;
    $queue->fail($lease, 'terminal', true);
    $results['failedUnscheduled'] = $store->connect()->zScore($key, serialize($data['queueId'])) === false;
    $newId = (new TaskFailedQueue($store))->retry($data['queueId']);
    $results['replayScheduled'] = $store->connect()->zScore($key, serialize($newId)) === 0.0;
    $payload = null;
    $queue->dispatch($newId, static function (array $record) use (&$payload): int { $payload = $record; return 0; });
    $queue->ack($queue->consume($payload));
    $results['ackUnscheduled'] = $store->connect()->zScore($key, serialize($newId)) === false;
    return $results;
}

$prefix = 'queue-index-test:' . bin2hex(random_bytes(8)) . ':';
$store = new QueueTestRedis($prefix);
try {
    $results = observeIdleIndex($store) + observeBatchLimit($store)
        + observeScheduleStates($store);
    echo json_encode($results, JSON_THROW_ON_ERROR);
} finally {
    $keys = $store->connect()->keys($prefix . '*');
    if ($keys !== []) $store->connect()->del($keys);
}
