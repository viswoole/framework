<?php
/**
 * 职责：用真实 Swoole IPC 对比首次与重试的数据和对象钩子次数；仅使用随机 Redis 键。
 */
declare(strict_types=1);

namespace Viswoole\Log\Facade {
    /**
     * 测试预期业务异常不进入真实日志后端。
     */
    final class Log
    {
        /**
         * 接收预期重试错误。
         * @param string $message 日志消息
         * @param array $context 上下文
         * @return void
         */
        public static function task(string $message, array $context = []): void {}
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/transport-payloads.php';

    use Viswoole\Core\Server\Task\TaskProxy;
    use Viswoole\Core\Server\Task\TaskQueue;

    /**
     * 在 Task Worker 内记录载荷，首次抛出异常，重试正常返回并 ACK。
     * @param QueueTestRedis $store 独立存储
     * @param TaskProxy $proxy 实际处理器代理
     * @return array 业务结果
     * @throws RuntimeException 首次执行时触发重试
     */
    function handleTransportPayload(QueueTestRedis $store, TaskProxy $proxy): array
    {
        $attempt = $store->inc('attempt:' . $proxy->queue_id);
        $payload = $proxy->data;
        if (!$payload instanceof TransportPayload) throw new RuntimeException('对象载荷类型无效');
        $store->set('observed:' . $proxy->queue_id . ':' . $attempt,
            ['metric' => $payload->metric(), 'bytes' => $payload->bytes()], 0);
        if ($attempt === 1) throw new RuntimeException('首次执行触发可重试错误');
        return ['ok' => true];
    }

    /**
     * 复用真实队列协议，推进退避后通过相同 IPC 再次投递。
     * @param Swoole\Server $server 真实服务
     * @param QueueTestRedis $store 独立存储
     * @param TransportPayload $payload 对象载荷
     * @return array 首次与重试观测
     * @throws Throwable 传输或存储异常
     */
    function runTransportCase(Swoole\Server $server, QueueTestRedis $store, TransportPayload $payload): array
    {
        $queue = new TaskQueue($store);
        $data = $queue->persist(['topic' => 'demo', 'data' => $payload], 'transport:0');
        $firstResult = $server->taskwait($data, 2);
        $before = $store->getQueueMetadata($data['queueId']);
        $after = $before;
        $after['retryAt'] = 0;
        if (!$store->updateQueue($data['queueId'], ['expected' => $before, 'value' => $after])) {
            throw new RuntimeException('推进退避失败');
        }
        $retryResult = null;
        $queue->dispatch($data['queueId'], static function (array $delivery) use ($server, &$retryResult): int {
            $retryResult = $server->taskwait($delivery, 2);
            return 0;
        });
        $first = $store->get('observed:' . $data['queueId'] . ':1');
        $second = $store->get('observed:' . $data['queueId'] . ':2');
        return ['firstMetric' => $first['metric'] === 1, 'retryMetric' => $second['metric'] === 1,
            'sameBytes' => $first['bytes'] === $second['bytes'] && $first['bytes'] === "\xff\0payload",
            'firstRetried' => $firstResult === false, 'retryResult' => $retryResult === ['ok' => true],
            'acked' => !$store->has($data['queueId'])];
    }

    $prefix = 'queue-ipc-test:' . bin2hex(random_bytes(8)) . ':';
    $server = new Swoole\Server('127.0.0.1', 0, SWOOLE_BASE);
    $server->set(['worker_num' => 1, 'task_worker_num' => 1, 'task_use_object' => true,
        'log_file' => sys_get_temp_dir() . '/queue-ipc-' . getmypid() . '.log']);
    $server->on('Receive', static function (): void {});
    $server->on('Finish', static function (): void {});
    $server->on('Task', static function (Swoole\Server $server, Swoole\Server\Task $task) use ($prefix): mixed {
        $store = new QueueTestRedis($prefix);
        $manager = new QueueTestManager($store);
        $manager->register('demo', static fn(TaskProxy $proxy): array => handleTransportPayload($store, $proxy));
        return $manager->accept($task->data, $server);
    });
    $server->on('WorkerStart', static function (Swoole\Server $server) use ($prefix): void {
        if ($server->taskworker) return;
        Swoole\Timer::after(30, static function () use ($server, $prefix): void {
            $store = new QueueTestRedis($prefix);
            try {
                $observations = [];
                foreach (['wake' => new WakeTransportPayload(), 'serialize' => new SerializeTransportPayload()] as $name => $payload) {
                    foreach (runTransportCase($server, $store, $payload) as $field => $value) {
                        $observations[$name . ':' . $field] = $value;
                    }
                }
                $observations['serializedOnce'] = SerializeTransportPayload::$serializations === 1;
                echo json_encode($observations, JSON_THROW_ON_ERROR);
            } finally {
                $keys = $store->connect()->keys($prefix . '*');
                if ($keys !== []) $store->connect()->del($keys);
                $server->shutdown();
            }
        });
    });
    $server->start();
}
