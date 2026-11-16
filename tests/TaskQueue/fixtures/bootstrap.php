<?php

declare(strict_types=1);

/* 职责：为独立队列测试加载框架源码并建立隔离 Redis 驱动，不初始化应用。 */

use Viswoole\Cache\Driver\Redis as RedisDriver;

spl_autoload_register(static function (string $class): void {
  if (!str_starts_with($class, 'Viswoole\\')) return;
  $path = dirname(__DIR__, 3) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
  if (is_file($path)) require_once $path;
});

/**
 * 独立连接避免 fork 后复用父进程的 Redis socket。
 */
final class QueueTestRedis extends RedisDriver
{
  /**
   * @var Redis 每个测试实例持有的独立原生连接
   */
  private Redis $connection;
  /**
   * @var bool 是否注入 pruneSet 故障，用于验证索引清理兜底路径
   */
  public bool $hasPruneFailure = false;

  public function __construct(string $prefix)
  {
    parent::__construct(prefix: $prefix);
    $this->connection = new Redis();
    $this->connection->connect(getenv('QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1', 6379, 2);
  }

  /**
   * @return Redis 每个测试实例独立的原生连接
   */
  public function connect(): Redis
  {
    return $this->connection;
  }

  /**
   * @return void 故障注入只覆盖辅助索引，消费载荷仍使用真实 Redis
   */
  public function pruneSet(string $key, string $registry, ?string $member = null): void
  {
    if ($this->hasPruneFailure) throw new RedisException('injected prune failure');
    parent::pruneSet($key, $registry, $member);
  }

  /**
   * @return void 只关闭本测试持有的连接，不修改协程上下文
   */
  public function close(): void
  {
    if (isset($this->connection)) $this->connection->close();
  }
}

/**
 * 只暴露真实 onTask 入口，不替换消费逻辑。
 */
final class QueueTestManager extends \Viswoole\Core\Server\TaskManager
{
  public function __construct(\Viswoole\Cache\Contract\CacheDriverInterface $store)
  {
    $this->queueDriver = $store;
  }

  /**
   * 覆写为缺省构造：测试环境无 Config 与日志容器，
   * 使用默认租约周期和 error_log 兜底日志路径。
   * @return TaskQueue 使用测试驱动的队列边界
   */
  protected function taskQueue(): \Viswoole\Core\Server\TaskQueue
  {
    return new \Viswoole\Core\Server\TaskQueue($this->queueDriver);
  }

  /**
   * @param array $data Swoole 载荷
   * @param \Swoole\Server $server 服务实例
   * @return mixed 真实处理器结果
   */
  public function accept(array $data, \Swoole\Server $server): mixed
  {
    $task = new \Swoole\Server\Task();
    $task->data = $data;
    $task->worker_id = 7;
    return $this->onTask($server, $task);
  }
}
