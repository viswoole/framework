<?php

declare(strict_types=1);

/* 职责：启动真实 Swoole 节点并驱动生产 TaskManager；只隔离应用路由、配置和日志装配。 */

namespace Viswoole\Core\Facade {
  /**
   * 测试节点的原生服务定位
   */
  final class Server
  {
    /**
     * @var \Swoole\Server 当前节点服务
     */
    public static \Swoole\Server $instance;
    /**
     * 获取本机原生服务
     * @return \Swoole\Server 真实服务
     */
    public static function getServer(): \Swoole\Server { return self::$instance; }
    /**
     * 获取本机 Worker 身份
     * @return int 当前进程身份
     */
    public static function getWorkerId(): int { return self::$instance->worker_id; }
  }
}

namespace Viswoole\Log\Facade {
  /**
   * 将场景日志写 stderr，不加载业务应用
   */
  final class Log
  {
    /**
     * 输出队列日志
     * @param string $message 消息
     * @param array $context 非业务载荷上下文
     * @return void 已输出
     */
    public static function task(string $message, array $context = []): void { error_log($message); }
  }
}

namespace {
  require __DIR__ . '/bootstrap.php';

  use Viswoole\Cache\CacheManager;
  use Viswoole\Cache\Contract\CacheDriverInterface;
  use Viswoole\Core\Config;
  use Viswoole\Core\Facade\Server;
  use Viswoole\Core\Server\ProcessRole;
  use Viswoole\Core\Server\ServerEventHook;
  use Viswoole\Core\Server\Shutdown\ShutdownRuntime;
  use Viswoole\Core\Server\Task\TaskManager;
  use Viswoole\Core\Server\Task\TaskProxy;
  use Viswoole\Core\Server\Task\TaskQueueRecoveryTimer;

  /**
   * 子进程按需创建 Redis 连接，禁止 fork 复用父连接
   */
  final class ClusterNodeCache extends CacheManager
  {
    /**
     * @var QueueTestRedis|null 当前进程独立商店
     */
    private ?QueueTestRedis $driver = null;
    /**
     * 保存隔离前缀
     * @param string $prefix 本轮节点共享的测试前缀
     */
    public function __construct(private readonly string $prefix) {}
    /**
     * 获取本进程商店
     * @param string|null $name 商店名
     * @return CacheDriverInterface 真实 Redis 驱动
     */
    public function store(?string $name = null): CacheDriverInterface { return $this->driver ??= new QueueTestRedis($this->prefix); }
  }

  /**
   * 仅提供当前节点基础设施配置
   */
  final class ClusterNodeConfig extends Config
  {
    /**
     * 保存节点身份
     * @param string $node 当前节点名称
     */
    public function __construct(private readonly string $node) {}
    /**
     * 获取测试配置
     * @param string|null $name 配置键
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    public function get(?string $name = null, mixed $default = null): mixed
    {
      return match ($name) {
        'task.instance_id' => $this->node,
        'task.lease_seconds' => 2,
        'task.concurrency_per_worker' => 1,
        'task.retry' => ['max_attempts' => 3, 'base_seconds' => 1, 'max_seconds' => 1],
        default => $default,
      };
    }
  }

  $spec = json_decode(file_get_contents(getenv('QUEUE_CLUSTER_NODE_SPEC')), true, flags: JSON_THROW_ON_ERROR);
  $node = $spec['node'];
  $prefix = $spec['prefix'];
  $cache = new ClusterNodeCache($prefix);
  $manager = new TaskManager($cache, new ClusterNodeConfig($node));
  // 协程 Task Worker 使用原生 finish 显式回传同步结果，验证本机路由而非原生自动返回差异。
  $manager->register('where', static function (TaskProxy $task) use ($node): void { $task->finish($node); });
  $manager->register('work', static function (TaskProxy $task) use ($cache, $node, $prefix): bool {
    $redis = $cache->store()->connect();
    $id = $task->queue_id;
    $running = $redis->hIncrBy($prefix . 'running', $node, 1);
    $redis->hSet($prefix . 'max', $node, max($running, (int)$redis->hGet($prefix . 'max', $node)));
    $redis->hSet($prefix . 'started', $id, $node);
    try {
      Swoole\Coroutine::sleep((float)$task->data['duration']);
      $record = $cache->store()->getQueueMetadata($id);
      $redis->hIncrBy($prefix . 'completions', $id, 1);
      $redis->hSet($prefix . 'results', $id, json_encode([
        'node' => $node, 'queueId' => $id, 'producer' => $record['producerInstanceId'], 'attempts' => $record['attempts'],
      ], JSON_THROW_ON_ERROR));
      return true;
    } finally {
      $redis->hIncrBy($prefix . 'running', $node, -1);
    }
  });
  $hooks = (new ReflectionProperty(ServerEventHook::class, 'handles'))->getValue();
  $workerStart = end($hooks['workerstart']);
  $onTask = end($hooks['task']);
  $server = new Swoole\Http\Server('127.0.0.1', $spec['port'], SWOOLE_PROCESS);
  $server->set(['worker_num' => 1, 'task_worker_num' => 1, 'task_enable_coroutine' => true,
    'reload_async' => true, 'max_wait_time' => 3, 'log_file' => $spec['log']]);
  Server::$instance = $server;
  ShutdownRuntime::prepare();
  $stop = static function (): void {
    (new ReflectionProperty(ShutdownRuntime::class, 'isStopping'))->getValue()->set(1);
    TaskQueueRecoveryTimer::stop();
  };
  $server->on('WorkerStart', static function (Swoole\Server $server, int $id) use ($workerStart): void {
    ProcessRole::markAsWorker();
    $workerStart($server, $id);
  });
  $server->on('Task', static fn (Swoole\Server $server, Swoole\Server\Task $task): mixed =>
    ShutdownRuntime::runTask($onTask, [$server, $task]));
  $server->on('Finish', static function (): void {});
  $server->on('BeforeShutdown', $stop);
  $server->on('WorkerExit', static function (): void { TaskQueueRecoveryTimer::stop(); });
  $server->on('Request', static function ($request, $response) use ($manager, $server, $stop, $node): void {
    $result = ['node' => $node];
    if ($request->server['request_uri'] === '/enqueue') {
      $result['ids'] = [];
      for ($index = 0; $index < (int)($request->get['count'] ?? 1); $index++) {
        $result['ids'][] = $manager->emit('work', ['duration' => (float)($request->get['duration'] ?? 0.1)]);
      }
    }
    if ($request->server['request_uri'] === '/sync') { $result['result'] = $manager->emitWait('where', [], 1); }
    if ($request->server['request_uri'] === '/stop') {
      $stop();
      Swoole\Timer::after(20, static fn (): bool => $server->shutdown());
    }
    $response->end(json_encode($result, JSON_THROW_ON_ERROR));
  });
  $server->start();
}
