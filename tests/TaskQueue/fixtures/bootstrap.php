<?php

declare(strict_types=1);

/**
 * 职责：为独立队列测试加载框架源码并建立隔离 Redis 驱动，不初始化应用。
 */

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

  /**
   * @var bool 是否模拟原子提交前存储故障
   */
  public bool $hasUpdateFailure = false;

  /**
   * @var Closure|null 原子提交前的一次性交错入口，不改变生产比较逻辑
   */
  public ?Closure $beforeUpdate = null;

  /**
   * @var Closure|null 隔离提交前的一次性交错入口
   */
  public ?Closure $beforeQuarantine = null;
  /**
   * @var bool 是否模拟隔离存储故障
   */
  public bool $hasQuarantineFailure = false;

  /**
   * 注入隔离前的并发更新，仍由生产 Lua 校验原始快照。
   * @param string $key 任务标识
   * @param string $snapshot 解析失败快照
   * @param string $reason 失败原因
   * @return bool 是否隔离成功
   * @throws RedisException 注入存储故障时抛出
   */
  public function quarantineQueue(string $key, string $snapshot, string $reason): bool
  {
    if ($this->hasQuarantineFailure) throw new RedisException('injected quarantine failure');
    if ($this->beforeQuarantine !== null) {
      $callback = $this->beforeQuarantine;
      $this->beforeQuarantine = null;
      $callback();
    }
    return parent::quarantineQueue($key, $snapshot, $reason);
  }

  /**
   * 转发原子提交，可在提交前注入存储故障
   * @param string $key 任务键
   * @param array $change 原子条件变更
   * @return bool 是否成功提交
   * @throws RedisException 注入故障时抛出
   */
  public function updateQueue(string $key, array $change): bool
  {
    if ($this->hasUpdateFailure) throw new RedisException('injected queue update failure');
    if ($this->beforeUpdate !== null) {
      $callback = $this->beforeUpdate;
      $this->beforeUpdate = null;
      $callback();
    }
    return parent::updateQueue($key, $change);
  }

  /**
   * 创建携带独立原生连接的测试驱动
   * @param string $prefix 隔离键前缀
   */
  public function __construct(string $prefix)
  {
    parent::__construct(prefix: $prefix);
    $this->connection = new Redis();
    $this->connection->connect(getenv('QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1', 6379, 2);
  }

  /**
   * 返回测试实例持有的独立原生连接
   * @return Redis 每个测试实例独立的原生连接
   */
  public function connect(): Redis
  {
    return $this->connection;
  }

  /**
   * 转发辅助索引清理，可注入清理故障
   * @return void 故障注入只覆盖辅助索引，消费载荷仍使用真实 Redis
   */
  public function pruneSet(string $key, string $registry, ?string $member = null): void
  {
    if ($this->hasPruneFailure) throw new RedisException('injected prune failure');
    parent::pruneSet($key, $registry, $member);
  }

  /**
   * 仅关闭测试持有的独立连接
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
final class QueueTestManager extends \Viswoole\Core\Server\Task\TaskManager
{
  /**
   * 以预置隔离商店构造测试管理器
   * @param \Viswoole\Cache\Contract\CacheDriverInterface $store 隔离商店
   * @param int $leaseSeconds 测试执行租约秒数
   */
  public function __construct(
    \Viswoole\Cache\Contract\CacheDriverInterface $store,
    /**
     * @var int 测试执行租约秒数
     */
    private readonly int $leaseSeconds = 30
  )
  {
    $this->queueDriver = $store;
  }

  /**
   * 覆写为缺省构造：测试环境无 Config 与日志容器，
   * 使用默认租约周期和 error_log 兜底日志路径。
   * @return TaskQueue 使用测试驱动的队列边界
   */
  protected function taskQueue(): \Viswoole\Core\Server\Task\TaskQueue
  {
    return new \Viswoole\Core\Server\Task\TaskQueue($this->queueDriver, $this->leaseSeconds);
  }

  /**
   * 以真实 onTask 入口执行模拟投递
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
