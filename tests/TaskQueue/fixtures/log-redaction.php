<?php
/**
 * 职责：验证恢复投递失败的日志仅包含摘要；不初始化业务日志后端。
 */
declare(strict_types=1);

namespace Viswoole\Log\Facade {
    /**
     * 捕获日志参数以检查业务载荷是否越过日志边界。
     */
    final class Log
    {
        /**
         * @var array 已收集的日志记录
         */
        public static array $entries = [];

        /**
         * 保存原始日志参数供测试断言。
         * @param string $message 日志消息
         * @param array $context 日志上下文
         * @return void
         */
        public static function task(string $message, array $context = []): void
        {
            self::$entries[] = [$message, $context];
        }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    use Viswoole\Core\Server\Task\TaskManager;
    use Viswoole\Core\Server\Task\TaskQueue;

    /**
     * 只模拟传输失败，恢复认领仍使用真实 Redis。
     */
    final class FailedTransport extends Swoole\Server
    {
        /**
         * 模拟 Swoole 返回明确的投递失败结果。
         * @param mixed $data 原始投递载荷
         * @param int $taskWorkerIndex 目标进程
         * @param callable|null $finishCallback 完成回调
         * @return int|false 固定返回 false
         */
        public function task(mixed $data, int $taskWorkerIndex = -1, ?callable $finishCallback = null): int|false
        {
            return false;
        }
    }

    /**
     * 暴露真实恢复入口，不注册其他服务生命周期回调。
     */
    final class RedactionManager extends TaskManager
    {
        /**
         * 注入已完成配置的生产队列。
         * @param TaskQueue $queue 真实队列
         */
        public function __construct(TaskQueue $queue)
        {
            $this->taskQueue = $queue;
        }

        /**
         * 固定队列实例，避免引入无关的应用配置。
         * @return TaskQueue 真实队列
         */
        protected function taskQueue(): TaskQueue
        {
            return $this->taskQueue;
        }

        /**
         * 调用生产恢复流程。
         * @param Swoole\Server $server 失败传输服务
         * @return void
         */
        public function recover(Swoole\Server $server): void
        {
            $this->recoverTasks($server);
        }
    }

    $prefix = 'queue-redaction-test:' . bin2hex(random_bytes(8)) . ':';
    $store = new QueueTestRedis($prefix);
    try {
        $queue = new TaskQueue($store);
        $data = $queue->persist(['topic' => 'sensitive', 'data' => ['secret' => 'do-not-log-this']], 'log:0');
        $before = $store->getQueueMetadata($data['queueId']);
        $after = $before;
        $after['leaseUntil'] = 0;
        $store->updateQueue($data['queueId'], ['expected' => $before, 'value' => $after]);
        (new RedactionManager($queue))->recover(new FailedTransport('127.0.0.1', 0, SWOOLE_BASE));
        $entries = \Viswoole\Log\Facade\Log::$entries;
        echo json_encode(['summaryLogged' => count($entries) === 1 && $entries[0][1] === ['topic' => 'sensitive', 'attempts' => 0],
            'payloadExcluded' => !str_contains(json_encode($entries, JSON_THROW_ON_ERROR), 'do-not-log-this')], JSON_THROW_ON_ERROR);
    } finally {
        $keys = $store->connect()->keys($prefix . '*');
        if ($keys !== []) $store->connect()->del($keys);
    }
}
