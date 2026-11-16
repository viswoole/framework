<?php
/**
 * 职责：以真实 Redis 和消费入口验证序列化失败、二进制错误和主题边界。
 */
declare(strict_types=1);

namespace Viswoole\Log\Facade {
    /**
     * 收集异常日志，避免应用启动或二进制输出干扰测试协议。
     */
    final class Log
    {
        /**
         * @var int 收到的日志条数
         */
        public static int $count = 0;

        /**
         * 记录日志已触达。
         * @param string $message 消息
         * @param array $context 上下文
         * @return void
         */
        public static function task(string $message, array $context = []): void
        {
            self::$count++;
        }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    use Viswoole\Core\Server\Task\TaskQueue;
    use Viswoole\Core\Server\Task\TaskFailedQueue;

    /**
     * 非字符串编码结果不能提交任何记录或索引。
     * @param QueueTestRedis $store 隔离存储
     * @return array 观测结果
     * @throws Throwable 场景初始化或存储失败时抛出
     */
    function observeEncoderFailures(QueueTestRedis $store): array
    {
        $results = [];
        foreach (['false' => false, 'null' => null, 'integer' => 0, 'array' => []] as $name => $value) {
            $calls = 0;
            $store->setSerialize(static function () use ($value, &$calls): mixed { ++$calls; return $value; });
            $before = $store->connect()->dbSize();
            try {
                (new TaskQueue($store))->persist(['topic' => 'demo'], 'encoding:0');
                $results['reject' . $name] = false;
            } catch (RuntimeException $exception) {
                $results['reject' . $name] = $calls === 1 && $store->connect()->dbSize() === $before
                    && str_contains($exception->getMessage(), '必须返回字符串');
            }
        }
        $store->setSerialize('json_encode', static fn(string $value): mixed => json_decode($value, true));
        $before = $store->connect()->dbSize();
        try {
            (new TaskQueue($store))->persist(['topic' => 'demo', 'data' => "bad:\xff"], 'encoding:0');
            $results['jsonFailureRejected'] = false;
        } catch (RuntimeException $exception) {
            $results['jsonFailureRejected'] = $store->connect()->dbSize() === $before;
        }
        $store->setSerialize();
        return $results;
    }

    /**
     * 二进制异常应立即进入退避，显式读取保留原始错误字节。
     * @param QueueTestRedis $store 隔离存储
     * @param \Swoole\Server $server 当前测试服务
     * @return array 观测结果
     * @throws Throwable 存储或消费失败时抛出
     */
    function observeBinaryErrors(QueueTestRedis $store, \Swoole\Server $server): array
    {
        $queue = new TaskQueue($store);
        $manager = new QueueTestManager($store);
        $reason = "remote:\xff\0";
        $manager->register('demo', static function () use ($reason): void { throw new RuntimeException($reason); });
        $data = $queue->persist(['topic' => 'demo'], 'binary:0');
        $isFailed = $manager->accept($data, $server) === false;
        $record = $store->get($data['queueId']);
        $results = ['binaryConsumerRetries' => $isFailed && $record['queueState'] === 'retry'
            && $record['attempts'] === 1 && $record['leaseUntil'] === 0 && $record['retryAt'] > microtime(true),
            'binaryConsumerReasonPreserved' => $record['lastError'] === RuntimeException::class . ': ' . $reason];
        $data = $queue->persist(['topic' => 'demo'], 'binary:0');
        $lease = $queue->consume($data);
        $queue->fail($lease, $reason, true);
        $failed = (new TaskFailedQueue($store))->get($data['queueId']);
        $results['binaryTerminalReasonPreserved'] = $failed['lastError'] === $reason;
        $newId = (new TaskFailedQueue($store))->retry($data['queueId']);
        $retried = $store->get($newId);
        $results['binaryFailureReplay'] = !$store->has($data['queueId']) && $retried['queueState'] === 'retry'
            && !array_key_exists('lastError', $retried) && !array_key_exists('lastErrorBase64', $retried);
        foreach (['binary' => $reason, 'empty' => '', 'zero' => '0'] as $name => $message) {
            $data = $queue->persist(['topic' => 'demo'], 'binary:0');
            $queue->deliveryFailed($data, $message);
            $record = $store->get($data['queueId']);
            $results[$name . 'DeliveryFailure'] = $record['queueState'] === 'retry'
                && $record['attempts'] === 0 && $record['lastError'] === $message;
        }
        return $results;
    }

    /**
     * 已编码的错误经过再次消费后可被新错误替换，旧执行权不能覆盖新状态。
     * @param QueueTestRedis $store 隔离存储
     * @return array 生命周期观测
     * @throws Throwable 存储或恢复失败时抛出
     */
    function observeErrorLifecycle(QueueTestRedis $store): array
    {
        $queue = new TaskQueue($store);
        $results = [];
        foreach (['php', 'json'] as $encoding) {
            if ($encoding === 'json') {
                $store->setSerialize('json_encode', static fn(string $value): mixed => json_decode($value, true));
            }
            $data = $queue->persist(['topic' => 'demo'], 'error-lifecycle:0');
            $lease = $queue->consume($data);
            $queue->fail($lease, "first:\xff");
            $before = $store->getQueueMetadata($data['queueId']);
            $after = $before;
            $after['retryAt'] = 0;
            if (!$store->updateQueue($data['queueId'], ['expected' => $before, 'value' => $after])) {
                throw new RuntimeException('推进重试期限失败');
            }
            $queue->recover(static function (array $delivery) use ($queue): int {
                $queue->fail($queue->consume($delivery), "second:\xfe\0", true);
                return 1;
            });
            $failed = (new TaskFailedQueue($store))->get($data['queueId']);
            $results[$encoding . 'ErrorReplaced'] = $failed['lastError'] === "second:\xfe\0" && $failed['attempts'] === 2;
            $results[$encoding . 'StaleFailureRejected'] = !$queue->fail($lease, 'stale', true)
                && (new TaskFailedQueue($store))->get($data['queueId'])['lastError'] === "second:\xfe\0";
            $results[$encoding . 'BinaryFailureDeleted'] = (new TaskFailedQueue($store))->delete($data['queueId']);
            $store->setSerialize();
        }
        return $results;
    }

    /**
     * 主题零可正常消费并 ACK；空主题在注册与落库前被拒绝。
     * @param QueueTestRedis $store 隔离存储
     * @param \Swoole\Server $server 当前测试服务
     * @return array 观测结果
     * @throws Throwable 存储或消费失败时抛出
     */
    function observeTopicBoundaries(QueueTestRedis $store, \Swoole\Server $server): array
    {
        $queue = new TaskQueue($store);
        $manager = new QueueTestManager($store);
        $manager->register('0', static fn(): int => 42);
        $data = $queue->persist(['topic' => '0'], 'zero:0');
        $results = ['zeroTopicConsumed' => $manager->accept($data, $server) === 42 && !$store->has($data['queueId']),
            'zeroTopicWithoutQueue' => $manager->accept(['topic' => '0'], $server) === 42];
        $actions = ['register' => static fn() => $manager->register('', static fn(): bool => true),
            'has' => static fn() => $manager->has(''), 'emit' => static fn() => $manager->emit('', []),
            'emitWait' => static fn() => $manager->emitWait('', []),
            'emitsWait' => static fn() => $manager->emitsWait(['' => []]),
            'emptyPersist' => static fn() => $queue->persist(['topic' => ''], 'zero:0'),
            'missingPersist' => static fn() => $queue->persist([], 'zero:0'),
            'integerPersist' => static fn() => $queue->persist(['topic' => 0], 'zero:0')];
        foreach ($actions as $name => $action) {
            try {
                $action();
                $results[$name . 'Rejected'] = false;
            } catch (InvalidArgumentException $exception) {
                $results[$name . 'Rejected'] = str_contains($exception->getMessage(), '非空字符串');
            }
        }
        return $results;
    }

    $prefix = 'queue-encoding-test:' . bin2hex(random_bytes(8)) . ':';
    $store = new QueueTestRedis($prefix);
    $server = new \Swoole\Server('127.0.0.1', 0, SWOOLE_BASE);
    try {
        echo json_encode(observeEncoderFailures($store) + observeErrorLifecycle($store) + observeBinaryErrors($store, $server)
            + observeTopicBoundaries($store, $server), JSON_THROW_ON_ERROR);
    } finally {
        $keys = $store->connect()->keys($prefix . '*');
        if ($keys !== []) $store->connect()->del($keys);
    }
}
