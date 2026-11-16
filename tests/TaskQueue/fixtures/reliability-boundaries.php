<?php
/**
 * 职责：收集隔离并发、缓存键共存和批次恢复观测；仅操作随机测试键。
 */
declare(strict_types=1);

namespace Viswoole\Log\Facade {
    /**
     * 隔离业务日志后端，避免故障注入输出污染 JSON。
     */
    final class Log
    {
        /**
         * 接收日志而不初始化应用容器。
         * @param string $message 日志消息
         * @param array $context 上下文
         * @return void
         */
        public static function task(string $message, array $context = []): void {}
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/isolation-support.php';

    use Viswoole\Core\Server\Task\TaskQueue;

    /**
     * 普通缓存空键不能成为未使用索引参数的隐式约束。
     * @param QueueTestRedis $store 商店
     * @return array 入队与 ACK 观测
 * @throws Throwable 场景存储或并发操作失败时抛出
     */
    function observeEmptyCacheKey(QueueTestRedis $store): array
    {
        $store->set('', 'ordinary cache value', 0);
        try {
            $queue = new TaskQueue($store);
            $data = $queue->persist(['topic' => 'demo'], 'empty-key:0');
            $lease = $queue->consume($data);
            return ['emptyKeyAllowsPersist' => is_array($lease),
                'emptyKeyAllowsAck' => $queue->ack($lease),
                'emptyKeyUnchanged' => $store->get('') === 'ordinary cache value'];
        } finally {
            $store->delete('');
        }
    }

    $prefix = 'queue-boundary-test:' . bin2hex(random_bytes(8)) . ':';
    $store = new QueueTestRedis($prefix);
    try {
        $results = observeEmptyCacheKey($store) + observeFullPoisonBatch($store)
            + observeConcurrentRepair($store) + observeQuarantineFailure($store)
            + observeBinaryQuarantine($store) + observeInvalidMetadata($store) + observeMissingRecordCleanup($store);
        $snapshots = createBrokenTask($store, 'contenders:0');
        runQuarantineContenders($prefix, $snapshots['id'], $snapshots['broken']);
        $results['oneQuarantineWinner'] = (int)$store->get('quarantine-winners') === 1;
        $results['concurrentSnapshotPreserved'] = (new TaskQueue($store))->quarantined()->get($snapshots['id'])['raw'] === $snapshots['broken'];
        echo json_encode($results, JSON_THROW_ON_ERROR);
    } finally {
        $keys = $store->connect()->keys($prefix . '*');
        if ($keys !== []) $store->connect()->del($keys);
    }
}
