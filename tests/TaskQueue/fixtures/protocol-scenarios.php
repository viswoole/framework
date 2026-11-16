<?php
/**
 * 职责：验证当前队列协议边界和单条异常隔离；仅操作随机测试键。
 */
declare(strict_types=1);

namespace Viswoole\Log\Facade {
    /**
     * 捕获生产恢复日志，不初始化应用日志服务。
     */
    final class Log
    {
        /**
         * @var list<string> 已记录的恢复异常
         */
        public static array $messages = [];

        /**
         * 保存消息以验证异常被记录。
         * @param string $message 日志消息
         * @param array $context 日志上下文
         * @return void
         */
        public static function task(string $message, array $context = []): void
        {
            self::$messages[] = $message;
        }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    use Viswoole\Cache\Driver\Redis\QueueRecord;
    use Viswoole\Core\Server\Task\TaskQueue;

    /**
     * 非队列信封内的对象不能在队列状态读取过程中被恢复。
     */
    final class UnsupportedPayload
    {
        /**
         * @var int 对象恢复次数
         */
        public static int $restorations = 0;

        /**
         * 记录恢复，以验证拒绝格式发生在反序列化之前。
         * @return void
         */
        public function __wakeup(): void
        {
            self::$restorations++;
        }
    }

    /**
     * 当前协议拒绝未封装记录，且不会自动迁移或覆盖原始数据。
     * @param QueueTestRedis $store 隔离商店
     * @return array 格式边界结果
     * @throws \Throwable 存储操作或场景初始化失败时抛出
     */
    function observeProtocolBoundary(QueueTestRedis $store): array
    {
        $record = ['queueId' => 'unsupported', 'topic' => 'demo', 'data' => new UnsupportedPayload()];
        $store->tag(TaskQueue::TAG_PREFIX . 'unused:0')->set('unsupported', $record, 0);
        $raw = $store->connect()->get($store->getCacheKey('unsupported'));
        $results = [];
        try {
            $store->getQueueMetadata('unsupported');
            $results['unsupportedReadRejected'] = false;
        } catch (RuntimeException $exception) {
            $results['unsupportedReadRejected'] = str_contains($exception->getMessage(), '记录格式无效');
        }
        try {
            $store->updateQueue('unsupported', ['expected' => $record, 'value' => $record]);
            $results['unsupportedUpdateRejected'] = false;
        } catch (RuntimeException $exception) {
            $results['unsupportedUpdateRejected'] = str_contains($exception->getMessage(), '记录格式无效');
        }
        $queue = new TaskQueue($store);
        $dispatches = 0;
        $queue->recover(static function () use (&$dispatches): int { return ++$dispatches; });
        $results['unsupportedNotMigrated'] = $dispatches === 0
            && $store->connect()->get($store->getCacheKey('unsupported')) === $raw;
        $results['unsupportedObjectNotRestored'] = UnsupportedPayload::$restorations === 0;
        return $results;
    }

    /**
     * 通过原子操作推进任务期限，保持调度索引一致。
     * @param QueueTestRedis $store 商店
     * @param string $id 任务标识
     * @return void
     * @throws \Throwable 原子状态更新失败时抛出
     */
    function expireProtocolTask(QueueTestRedis $store, string $id): void
    {
        $before = $store->getQueueMetadata($id);
        $after = $before;
        $after['leaseUntil'] = 0;
        if (!$store->updateQueue($id, ['expected' => $before, 'value' => $after])) {
            throw new RuntimeException('推进测试租约失败');
        }
    }

    /**
     * 一条损坏记录应被记录日志，本轮其他健康任务仍可消费。
     * @param QueueTestRedis $store 隔离商店
     * @return array 故障隔离结果
     * @throws \Throwable 存储操作或测试任务构造失败时抛出
     */
    function observeRecordIsolation(QueueTestRedis $store): array
    {
        $queue = new TaskQueue($store);
        $broken = $queue->persist(['topic' => 'demo'], 'broken:0');
        $healthy = $queue->persist(['topic' => 'demo'], 'healthy:0');
        expireProtocolTask($store, $broken['queueId']);
        expireProtocolTask($store, $healthy['queueId']);
        $store->connect()->set($store->getCacheKey($broken['queueId']), QueueRecord::PREFIX . '{invalid');
        $dispatches = 0;
        $queue->recover(static function (array $data) use ($queue, &$dispatches): int {
            if (!$queue->ack($queue->consume($data))) throw new RuntimeException('健康任务消费失败');
            return ++$dispatches;
        });
        return ['healthyRecovered' => $dispatches === 1 && !$store->has($healthy['queueId']),
            'brokenRecordLogged' => count(\Viswoole\Log\Facade\Log::$messages) === 1];
    }

    $prefix = 'queue-protocol-test:' . bin2hex(random_bytes(8)) . ':';
    $store = new QueueTestRedis($prefix);
    try {
        $results = observeProtocolBoundary($store) + observeRecordIsolation($store);
        $store->set('ordinary-cache', ['value' => 42], 0);
        $results['ordinaryCacheUnaffected'] = $store->get('ordinary-cache') === ['value' => 42];
        echo json_encode($results, JSON_THROW_ON_ERROR);
    } finally {
        $keys = $store->connect()->keys($prefix . '*');
        if ($keys !== []) $store->connect()->del($keys);
    }
}
