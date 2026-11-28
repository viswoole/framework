<?php

declare(strict_types=1);

/* 职责：隔离真实领取trait的可控时钟场景，不启动服务或访问外部存储。 */

namespace Viswoole\Core\Server\Task {
  /**
   * 获取场景可控时间
   *
   * 只在本子进程替换非限定时钟调用，避免依赖机器负载复现重复节流。
   *
   * @param bool $asFloat 是否返回浮点数
   * @return float 当前场景时间
   */
  function microtime(bool $asFloat = false): float
  {
    return \CadenceClock::$now;
  }
}

namespace {
  require __DIR__ . '/bootstrap.php';

  use Viswoole\Core\Server\Shutdown\ShutdownRuntime;
  use Viswoole\Core\Server\Task\TaskClusterDispatch;
  use Viswoole\Core\Server\Task\TaskQueue;

  /**
   * 由场景推进的单调输入
   */
  final class CadenceClock
  {
    /**
     * @var float 当前模拟秒数
     */
    public static float $now = 100.0;
  }

  /**
   * 仅观察轮次入口，不模拟真实Redis状态协议
   */
  final class CadenceQueue
  {
    /**
     * @var int 已调用领取轮次数
     */
    public int $calls = 0;
    /**
     * @var int 当前在途数量
     */
    public int $active = 0;
    /**
     * @var int 本轮成功投递数量
     */
    public int $processed = 1;
    /**
     * @var Closure|null 领取期间的一次重入
     */
    public ?Closure $duringRecovery = null;

    /**
     * 返回模拟在途数量
     *
     * @param string $instance 当前节点身份
     * @return int 当前在途数量
     */
    public function assignedCount(string $instance): int
    {
      return $this->active;
    }

    /**
     * 模拟一次有耗时的领取
     *
     * 不调用本机IPC，真实Redis和容量协议由集群集成测试覆盖。
     *
     * @param Closure $dispatch 本机投递回调
     * @param array<string,mixed> $options 领取上下文
     * @return int 本轮成功投递数量
     */
    public function recover(Closure $dispatch, array $options): int
    {
      $this->calls++;
      CadenceClock::$now += 0.01;
      $callback = $this->duringRecovery;
      $this->duringRecovery = null;
      $callback?->__invoke();
      return $this->processed;
    }
  }

  class_alias(CadenceQueue::class, TaskQueue::class);

  /**
   * 固定有效的本机并发配置
   */
  final class CadenceConfig
  {
    /**
     * 返回本场景并发预算
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return int 每进程并发预算
     */
    public function get(string $key, mixed $default): int
    {
      return 8;
    }
  }

  /**
   * 暴露真实trait入口
   */
  final class CadenceHarness
  {
    use TaskClusterDispatch;

    /**
     * @var CadenceConfig 有效的节点配置
     */
    protected CadenceConfig $config;
    /**
     * @var string 测试节点身份
     */
    protected string $instanceId = 'cadence';
    /**
     * @var array<string,bool> 已注册主题
     */
    protected array $topics = ['known' => true];
    /**
     * @var CadenceQueue 轮次观察器
     */
    public CadenceQueue $queue;

    /**
     * 创建本场景入口
     *
     * 不构造生产TaskManager，避免注册真实服务事件。
     *
     * @return void 观察器已绑定
     */
    public function __construct()
    {
      $this->config = new CadenceConfig();
      $this->queue = new CadenceQueue();
    }

    /**
     * 获取轮次观察器
     *
     * @return TaskQueue 子进程别名观察器
     */
    protected function taskQueue(): TaskQueue
    {
      return $this->queue;
    }

    /**
     * 驱动真实领取入口
     *
     * @param Swoole\Server $server 未启动的本机服务配置
     * @return void 轮次已尝试
     */
    public function tick(Swoole\Server $server): void
    {
      $this->recoverTasks($server);
    }
  }

  $server = new Swoole\Http\Server('127.0.0.1', 0, SWOOLE_BASE);
  $server->set(['worker_num' => 1, 'task_worker_num' => 2, 'task_enable_coroutine' => true]);
  ShutdownRuntime::prepare();
  $harness = new CadenceHarness();
  $scenario = getenv('QUEUE_CADENCE_SCENARIO');
  if ($scenario === 'idle') { $harness->queue->processed = 0; }
  if ($scenario === 'assigned') { $harness->queue->active = 1;
    $harness->queue->processed = 0; }
  if ($scenario === 'guard') {
    $harness->queue->duringRecovery = static function () use ($harness, $server): void {
      CadenceClock::$now = 100.1;
      $harness->tick($server);
    };
  }
  $harness->tick($server);
  $first = $harness->queue->calls;
  CadenceClock::$now = 100.1;
  if ($scenario === 'guard') {
    (new ReflectionProperty(ShutdownRuntime::class, 'isStopping'))->getValue()->set(1);
  }
  $harness->tick($server);
  $second = $harness->queue->calls;
  CadenceClock::$now = 101.1;
  if ($scenario === 'idle') { $harness->tick($server); }
  echo json_encode(['calls' => $scenario === 'idle' ? [$first, $second, $harness->queue->calls] : $second], JSON_THROW_ON_ERROR);
}
