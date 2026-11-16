<?php
/**
 * 职责：协调主题、持久化投递和消费入口；存储状态与执行生命周期委托专用模块。
 */
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use InvalidArgumentException;
use RuntimeException;
use Swoole\Server as SwooleServer;
use Swoole\Server\Task as SwooleTask;
use Throwable;
use Viswoole\Cache\CacheManager;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Core\Config;
use Viswoole\Core\Facade\Server;
use Viswoole\Core\Server\ServerEventHook;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;

/**
 * 任务管理器
 *
 * 提供 Swoole Task 任务的注册、投递和队列持久化能力，
 * 服务重启时自动恢复未完成的队列任务。
 * 注意：使用任务管理服务必须配置服务选项：
 * `Constant::OPTION_TASK_USE_OBJECT => true` 或 `Constant::OPTION_TASK_ENABLE_COROUTINE => true`
 */
class TaskManager
{
  use TaskTopicRegistry;
  use TaskSynchronousDispatch;

  /**
   * @var array<string,callable> 任务主题
   */
  protected array $topics = [];
  /**
   * @var CacheDriverInterface|null 任务队列专用缓存驱动（惰性解析）
   */
  protected ?CacheDriverInterface $queueDriver = null;
  /**
   * @var string 实例归属在 fork 前确定，恢复时仍允许其他实例接管
   */
  protected string $instanceId;
  /**
   * @var TaskQueue|null 队列存储与认领边界（惰性创建，进程级复用）
   */
  protected ?TaskQueue $taskQueue = null;

  /**
   * @param CacheManager $cache 缓存管理器，用于任务队列的持久化存储
   * @param Config $config 框架配置实例，用于读取 task.store 队列缓存通道
   *   与 task.lease_seconds 租约及 task.retry 重试配置
   */
  public function __construct(
    /**
     * @var CacheManager 缓存商店解析入口
     */
    protected CacheManager $cache,
    /**
     * @var Config 任务配置读取入口
     */
    protected Config       $config
  )
  {
    $this->instanceId = (string)($this->config->get('task.instance_id') ?: bin2hex(
      random_bytes(16)
    ));
    ServerEventHook::addEvent('workerStart', function (SwooleServer $server, int $workerId): void {
      $this->startQueueRecovery($server, $workerId);
    });
    ServerEventHook::addEvent('task', function (SwooleServer $server, SwooleTask $task): mixed {
      // 透传 onTask 返回值（Swoole 语义：onTask 返回值作为任务结果发送给 Worker 进程）
      return $this->onTask($server, $task);
    });
  }

  /**
   * 启动期解析失败只留痕；emit() 实际使用队列时仍明确报告配置错误。
   * @param SwooleServer $server 服务实例
   * @param int $workerId 本地 Worker ID
   * @return void
   */
  protected function startQueueRecovery(SwooleServer $server, int $workerId): void
  {
    if ($server->taskworker || $workerId !== 0) return;
    try {
      if (!TaskQueue::isSupported($this->queueStore())) return;
      $this->taskQueue();
    } catch (Throwable $exception) {
      TaskQueueLog::write(
        '任务队列缓存商店不可用，恢复扫描已跳过', ['exception' => (string)$exception]
      );
      return;
    }
    $recover = function () use ($server): void {
      $this->recoverTasks($server);
    };
    $recover();
    // 启动扫描之外持续错峰重试，才能接管持有租约时崩溃的实例。
    TaskQueueRecoveryTimer::start(1000, $recover);
  }

  /**
   * 惰性解析任务队列专用缓存驱动
   *
   * 读取 task.store 配置指定缓存商店名称；未配置时使用默认通道。
   * 采用惰性解析的原因：TaskManager 在服务启动阶段即被实例化，
   * 急切解析会使未配置缓存商店的项目在启动时直接崩溃，
   * 即使它从未使用任务队列功能。惰性将失败时机推迟到首次实际使用队列。
   *
   * @return CacheDriverInterface 任务队列专用缓存驱动实例
   */
  protected function queueStore(): CacheDriverInterface
  {
    if (!isset($this->queueDriver)) {
      // 未配置时 store(null) 返回默认通道；指定不存在的名称会抛 CacheErrorException，快速失败
      $this->queueDriver = $this->cache->store($this->config->get('task.store'));
    }
    return $this->queueDriver;
  }

  /**
   * 获取或创建当前实例的队列存储边界，校验成功后才发布缓存实例
   * @return TaskQueue 队列存储与认领边界；实例缓存复用，租约和重试策略在首次使用时固化
   * @throws InvalidArgumentException 租约或重试配置无效时抛出
   * @throws RuntimeException 缓存驱动不支持可靠队列时抛出
   */
  protected function taskQueue(): TaskQueue
  {
    if (!isset($this->taskQueue)) {
      $queue = new TaskQueue(
        $this->queueStore(),
        (int)($this->config->get('task.lease_seconds') ?: TaskQueue::LEASE_SECONDS)
      );
      $queue->configureRetry($this->config->get('task.retry') ?? []);
      $this->taskQueue = $queue;
    }
    return $this->taskQueue;
  }

  /**
   * 扫描故障与单条投递故障分别留痕，日志故障不打断周期恢复。
   * @param SwooleServer $server 服务实例
   * @return void
   */
  protected function recoverTasks(SwooleServer $server): void
  {
    if (ShutdownRuntime::isStopping()) return;
    try {
      $this->taskQueue()->recover(function (array $data) use ($server): int|false {
        try {
          $result = $server->task($data);
          if ($result === false) TaskQueueLog::write('队列任务恢复投递失败：' . $data['queueId'], [
            'topic' => $data['topic'], 'attempts' => $data['attempts'] ?? 0,
          ]);
          return $result;
        } catch (Throwable $exception) {
          TaskQueueLog::write(
            '队列任务恢复投递异常：' . $data['queueId'], ['exception' => (string)$exception]
          );
          return false;
        }
      });
    } catch (Throwable $exception) {
      TaskQueueLog::write('队列恢复异常，将于下个周期重试', ['exception' => (string)$exception]);
    }
  }

  /**
   * 处理 Swoole onTask 事件，将任务分发到对应主题的处理器
   *
   * 处理器返回值原样透传（Swoole onTask 官方语义：返回值作为任务结果
   * 发送给 Worker 进程的 onFinish 回调 / taskwait 调用方）。
   * 任务已过期或处理器抛出异常时返回 false。
   *
   * @param SwooleServer $server Swoole 服务实例
   * @param SwooleTask $task Swoole 任务对象
   * @return mixed 处理器返回值；任务已过期或处理器抛出异常时返回 false
   * @throws InvalidArgumentException 投递主题不是非空字符串时抛出
   */
  protected function onTask(SwooleServer $server, SwooleTask $task): mixed
  {
    $topic = TaskTopicName::validate($task->data['topic'] ?? null);
    if (empty($task->data['queueId'])) {
      $this->has($topic);
      if ($this->isExpired($task->data)) return false;
      try {
        return ($this->topics[strtolower($topic)])(new TaskProxy($task), $server);
      } catch (Throwable $exception) {
        TaskQueueLog::write('任务执行异常', ['exception' => (string)$exception]);
        return false;
      }
    }
    return new TaskQueueConsumer($this->taskQueue())->execute($task, $server, [
      'validate' => function () use ($topic, $task): void {
        $this->has($topic);
        if ($this->isExpired($task->data)) throw new RuntimeException('任务已过期');
      },
      'handle' => fn(TaskProxy $proxy, SwooleServer $server): mixed => ($this->topics[strtolower(
        $topic
      )])(
        $proxy, $server
      ),
    ]);
  }

  /**
   * 投递异步任务到 Task Worker 进程
   *
   * 投递时按 task.topics / task.expire 配置解析任务有效期（秒）并随任务
   * 数据传递，任务被消费时检测，已过期的任务不会执行处理器。
   *
   * @param string $topic 已注册的任务主题名称
   * @param mixed $data 传递给任务处理器的业务数据
   * @param bool $queue 是否持久化到队列，启用后服务重启可自动恢复未执行的任务
   * @return int|false 投递成功返回任务 ID；返回 false 时已持久化的任务仍会恢复重投
   * @throws Throwable 队列缓存写入失败时抛出
   */
  public function emit(
    string $topic,
    mixed  $data,
    bool   $queue = true,
  ): int|false
  {
    if (ShutdownRuntime::isStopping()) return false;
    self::has($topic);
    $taskData = [
      'data' => $data,
      'topic' => $topic,
      'queueId' => null,
      'dispatch_time' => microtime(true),
      // 任务有效期（秒），null 表示长期有效，消费时由 isExpired 检测
      'expire' => $this->resolveExpire($topic),
    ];
    if (!$queue) return Server::getServer()->task($taskData);
    $taskQueue = $this->taskQueue();
    $group = $this->instanceId . ':' . Server::getWorkerId();
    $taskData = $taskQueue->persist($taskData, $group);
    try {
      $result = Server::getServer()->task($taskData);
    } catch (Throwable $exception) {
      $taskQueue->deliveryFailed($taskData, $exception->getMessage());
      throw $exception;
    }
    if ($result === false) $taskQueue->deliveryFailed($taskData, 'Swoole 首次投递失败');
    return $result;
  }

  /**
   * 获取失败队列管理入口，使用与消费者相同的商店。
   * @return TaskFailedQueue 失败任务管理器
   */
  public function failedQueue(): TaskFailedQueue
  {
    $this->taskQueue();
    return new TaskFailedQueue($this->queueStore());
  }

  /**
   * 查询恢复阶段隔离的损坏记录。
   * @return TaskQuarantinedQueue 原始快照与原因查询入口
   * @throws Throwable 队列初始化失败时抛出
   */
  public function quarantinedQueue(): TaskQuarantinedQueue
  {
    return $this->taskQueue()->quarantined();
  }
}
