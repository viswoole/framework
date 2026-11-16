<?php

/*
 * 职责：注入 IPC 与 Redis 响应丢失；不替换真实队列状态和索引提交逻辑。
 */
declare(strict_types=1);

use Viswoole\Cache\Driver\Redis as RedisDriver;
use Viswoole\Core\Server\Task\TaskManager;

/**
 * 保留真实 Lua 写入，以区分提交前断线和提交后丢失响应。
 */
final class AcceptanceRedis extends Redis
{
  /**
   * @var string 当前故障模式
   */
  public string $mode = '';

  /**
   * @var int 原子命令调用次数，用于证明连接获取失败没有执行 EVAL
   */
  public int $evalCalls = 0;

  /**
   * 注入原子提交边界故障
   *
   * @param string $script 生产脚本
   * @param array $arguments 脚本参数
   * @param int $keyCount 键数量
   * @return mixed Redis 原生结果
   * @throws RedisException 模拟连接丢失
   */
  public function eval(string $script, array $arguments = [], int $keyCount = 0): mixed
  {
    $this->evalCalls++;
    $mode = $this->mode;
    $this->mode = '';
    if ($mode === 'before') { throw new RedisException('connection lost before reply'); }
    if ($mode === 'false') { return false; }
    $result = parent::eval($script, $arguments, $keyCount);
    if ($mode === 'after') { throw new RedisException('reply lost after commit'); }
    return $result;
  }
}

/**
 * 独立原生连接及投递信封读取故障。
 */
final class AcceptanceStore extends RedisDriver
{
  /**
   * @var AcceptanceRedis 测试独立连接
   */
  public AcceptanceRedis $connection;
  /**
   * @var bool 是否模拟提交后读取故障
   */
  public bool $hasReadFailure = false;

  /**
   * @var int|null 成功获取指定次数连接后注入一次故障，null 表示不注入
   */
  public ?int $connectionsBeforeFailure = null;

  /**
   * 初始化隔离存储
   *
   * @param string $prefix 随机测试前缀
   */
  public function __construct(string $prefix)
  {
    parent::__construct(prefix: $prefix);
    $this->connection = new AcceptanceRedis();
    $this->connection->connect(getenv('QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1', 6379, 2);
  }

  /**
   * 返回独立测试连接
   *
   * @return Redis 原生连接
   * @throws RedisException 注入命令执行前的连接获取故障
   */
  public function connect(): Redis
  {
    if ($this->connectionsBeforeFailure === 0) {
      $this->connectionsBeforeFailure = null;
      throw new RedisException('connection acquisition failed before eval');
    }
    if ($this->connectionsBeforeFailure !== null) { $this->connectionsBeforeFailure--; }
    return $this->connection;
  }

  /**
   * 在已提交后注入信封读取失败
   *
   * @param string $key 队列 ID
   * @return array|null 原始投递信封
   * @throws RedisException 注入读取故障
   */
  public function getQueueDelivery(string $key): ?array
  {
    if ($this->hasReadFailure) { throw new RedisException('delivery read unavailable'); }
    return parent::getQueueDelivery($key);
  }
}

/**
 * 只跳过应用注册，复用真实生产投递入口。
 */
final class AcceptanceManager extends TaskManager
{
  /**
   * 注入测试存储与缺省配置
   *
   * @param AcceptanceStore $store 隔离商店
   */
  public function __construct(AcceptanceStore $store)
  {
    $this->queueDriver = $store;
    $this->config = new ReviewRetryConfig([]);
    $this->instanceId = 'acceptance';
    $this->register('demo', static fn (): bool => true);
  }
}

/**
 * 只注入首次 IPC 结果，恢复路径另行走真实队列。
 */
final class AcceptanceTransport
{
  /**
   * @var string IPC 故障模式
   */
  public string $mode = '';
  /**
   * @var AcceptanceStore 用于模拟补偿写入失败的商店
   */
  public AcceptanceStore $store;

  /**
   * 返回预设 IPC 结果
   *
   * @param array $data 生产投递信封
   * @return int|false 原生投递结果
   * @throws RuntimeException 模拟 IPC 异常
   */
  public function task(array $data): int|false
  {
    if ($this->mode === 'throw') { throw new RuntimeException('IPC unavailable'); }
    if ($this->mode === 'retry-write') { $this->store->connection->mode = 'before'; }
    return $this->mode === '' ? 0 : false;
  }
}
