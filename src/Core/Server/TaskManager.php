<?php
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

namespace Viswoole\Core\Server;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Swoole\Server as SwooleServer;
use Swoole\Server\Task as SwooleTask;
use Throwable;
use Viswoole\Cache\CacheManager;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Core\Config;
use Viswoole\Core\Facade\Server;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;
use Viswoole\Log\Facade\Log;

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
  /**
   * @var string 任务队列缓存标签前缀
   */
  const string CACHE_TAG_PREFIX = TaskQueue::TAG_PREFIX;
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
   *   与 task.lease_seconds 投递租约周期配置
   */
  public function __construct(
    protected CacheManager $cache,
    protected Config       $config
  ) {
    $this->instanceId = (string)($this->config->get('task.instance_id') ?: bin2hex(random_bytes(16)));
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
      $taskQueue = $this->taskQueue();
    } catch (Throwable $exception) {
      TaskQueueLog::write('任务队列缓存商店不可用，恢复扫描已跳过', ['exception' => (string)$exception]);
      return;
    }
    $recover = function () use ($server): void { $this->recoverTasks($server); };
    $recover();
    // 启动扫描之外持续错峰重试，才能接管持有租约时崩溃的实例。
    TaskQueueRecoveryTimer::start($taskQueue->leaseSeconds * 1000, $recover);
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
          if ($result === false) TaskQueueLog::write('队列任务恢复投递失败：' . $data['queueId'], $data);
          return $result;
        } catch (Throwable $exception) {
          TaskQueueLog::write('队列任务恢复投递异常：' . $data['queueId'], ['exception' => (string)$exception]);
          return false;
        }
      });
    } catch (Throwable $exception) {
      TaskQueueLog::write('队列恢复异常，将于下个周期重试', ['exception' => (string)$exception]);
    }
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
   * 处理 Swoole onTask 事件，将任务分发到对应主题的处理器
   *
   * 处理器返回值原样透传（Swoole onTask 官方语义：返回值作为任务结果
   * 发送给 Worker 进程的 onFinish 回调 / taskwait 调用方）。
   * 任务已过期或处理器抛出异常时返回 false。
   *
   * @param SwooleServer $server Swoole 服务实例
   * @param SwooleTask $task Swoole 任务对象
   * @return mixed 处理器返回值；任务已过期或处理器抛出异常时返回 false
   */
  protected function onTask(SwooleServer $server, SwooleTask $task): mixed
  {
    $topic = $task->data['topic'] ?? null;
    if (empty($topic)) {
      throw new InvalidArgumentException('必须使用该类中的方法触发任务');
    }
    $taskProxy = new TaskProxy($task);
    // 原子消费先于主题校验和处理器执行：租约过期后的重复投递只有一个胜者；
    // 主题未注册时条目也会被消费删除，避免恢复扫描将不可执行的任务无限重投。
    if ($taskProxy->queue_id && !$this->taskQueue()->consume($task->data, (string)$taskProxy->worker_id)) {
      return false;
    }
    self::has($topic);
    // 过期检测：有效期在投递时按主题解析并随任务数据传递，
    // 超过有效期的任务不再执行处理器，直接返回 false 通知 Worker 进程
    if ($this->isExpired($task->data)) {
      Log::task("任务已过期，跳过执行：{$taskProxy->topic}({$taskProxy->queue_id})", [
        'expire' => $task->data['expire'] ?? null,
        'dispatch_time' => $task->data['dispatch_time'] ?? null,
        'data' => $taskProxy->data,
      ]);
      return false;
    }
    $handle = $this->topics[strtolower($topic)];
    try {
      // 处理器返回值原样透传给 Worker 进程（void 处理器返回 null，不会触发结果投递）
      return call_user_func_array($handle, [$taskProxy, $server]);
    } catch (Throwable $e) {
      // 任务异常仅记录到任务日志通道，队列条目已在消费时删除，不会重投；
      // 返回 false 让等待方（taskwait）立即收到失败信号，而不是干等超时
      Log::task("任务执行异常：$taskProxy->topic($taskProxy->queue_id)", [
        'exception' => (string)$e,
        'data' => $taskProxy->data,
      ]);
      return false;
    }
  }

  /**
   * 解析指定主题的任务有效期（秒）
   *
   * 优先级：task.topics 中按主题配置 > task.expire 全局默认。
   * 主题名本身可包含点号（如 sms.sendLoginCode），无法使用点号分隔的
   * 配置路径查询，因此对 topics 配置做整体扫描匹配（不区分大小写）。
   * 0、负数等无效值统一归一化为 null（长期有效）。
   *
   * @param string $topic 任务主题名称
   * @return int|null 有效期秒数，null 表示长期有效
   */
  protected function resolveExpire(string $topic): ?int
  {
    $expire = null;
    $matched = false;
    $topics = $this->config->get('task.topics');
    if (is_array($topics)) {
      foreach ($topics as $key => $value) {
        // 主题名不区分大小写，与主题注册规则保持一致
        if (strcasecmp((string)$key, $topic) === 0) {
          $expire = $value;
          $matched = true;
          break;
        }
      }
    }
    // 未命中主题配置时回退到全局默认；命中且值为 null 表示该主题豁免（长期有效）
    if (!$matched) $expire = $this->config->get('task.expire');
    return is_numeric($expire) && (int)$expire > 0 ? (int)$expire : null;
  }

  /**
   * 检测任务是否已超过有效期
   *
   * @param array $taskData 任务数据（含 expire 与 dispatch_time）
   * @return bool 已过期返回 true；未配置有效期、未记录投递时间或未过期返回 false
   */
  protected function isExpired(array $taskData): bool
  {
    $expire = $taskData['expire'] ?? null;
    $dispatchTime = $taskData['dispatch_time'] ?? null;
    // 0、null 或负数均视为长期有效（与缓存过期时间约定一致）
    if (!is_numeric($expire) || (int)$expire <= 0) return false;
    if (!is_numeric($dispatchTime)) return false;
    return microtime(true) > ((float)$dispatchTime + (int)$expire);
  }

  /**
   * 校验任务主题是否已注册
   *
   * @param string $topic 任务主题名称
   * @throws InvalidArgumentException 任务主题未注册时抛出
   */
  public function has(string $topic): void
  {
    $result = isset($this->topics[strtolower($topic)]);
    if (!$result) throw new InvalidArgumentException("没有找到任务主题：$topic");
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
   * @return int|false 投递成功返回任务ID，投递失败返回 false
   * @throws RuntimeException 队列缓存写入失败时抛出
   */
  public function emit(
    string $topic,
    mixed  $data,
    bool   $queue = true,
  ): int|false {
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
    $result = Server::getServer()->task($taskData);
    if ($result === false) $taskQueue->discard($taskData);
    return $result;
  }

  /**
   * @return TaskQueue 队列存储与认领边界；实例缓存复用，租约周期与日志通道在构造时固化
   */
  protected function taskQueue(): TaskQueue
  {
    if (!isset($this->taskQueue)) {
      $this->taskQueue = new TaskQueue(
        $this->queueStore(),
        (int)($this->config->get('task.lease_seconds') ?: TaskQueue::LEASE_SECONDS),
        fn(string $message, Throwable $exception): mixed => TaskQueueLog::write($message, ['exception' => (string)$exception])
      );
    }
    return $this->taskQueue;
  }

  /**
   * 注册任务主题及其处理器
   *
   * 支持两种注册方式：
   * - 传入 callable：直接注册为指定主题的处理器
   * - 传入类名：自动扫描该类的所有公开方法，以 "主题.方法名" 的形式批量注册
   *
   * 示例：
   *
   * ```
   * $taskManager->register('test', function (TaskProxy $task) {
   *   // 任务执行完毕调用finish
   *   $task->finish('success');
   * })
   * // 该类用于模拟发送短信
   * class Sms {
   *   // 静态方法 发送登录验证码
   *   public static function sendLoginCode(TaskProxy $task) {
   *      $phone = $task->data['phone'];
   *     // ...发送验证码业务逻辑
   *     // 发送验证码完成
   *     $task->finish('success');
   *   }
   *   // 动态方法 发送注册验证码
   *   public function sendRegisterCode(TaskProxy $task) {
   *      $phone = $task->data['phone'];
   *     // ...发送验证码业务逻辑
   *     // 发送验证码完成
   *     $task->finish('success');
   *   }
   * }
   * // 注册一个类，支持静态方法、动态方法
   * $taskManager->register('sms', Sms::class);//将会注册sms.sendLoginCode、sms.sendRegisterCode这两个主题
   * ```
   *
   * @param string $topic 任务主题名称，不区分大小写；传入类名时作为命名前缀
   * @param callable|string $handle 任务处理回调，或待扫描的类名
   * @throws InvalidArgumentException 类名无效或反射失败时抛出
   */
  public function register(string $topic, callable|string $handle): void
  {
    if (!is_callable($handle)) {
      try {
        $refClass = new ReflectionClass($handle);
        // 获取类的方法
        $methods = $refClass->getMethods();
        foreach ($methods as $method) {
          $methodName = $method->getName();
          // 修复: 过滤掉魔术方法(以 __ 开头, 如 __construct、__destruct、__get 等),
          // 这些方法不应被注册为任务方法
          if (str_starts_with($methodName, '__')) continue;
          if ($method->isStatic()) {
            $h = "$handle::$methodName";
          } else {
            $h = [
              $handle,
              $methodName
            ];
          }
          $this->topics[strtolower($topic . '.' . $methodName)] = $h;
        }
      } catch (ReflectionException $e) {
        $message = $e->getMessage();
        throw new InvalidArgumentException("Invalid handle: $handle , $message");
      }
    } else {
      $this->topics[strtolower($topic)] = $handle;
    }
  }

  /**
   * 同步阻塞投递任务并等待执行结果
   *
   * 任务处理器通过 finish() 或 return 回传的数据类型原样透传
   * （数组、整型、null、'' 等任意非空值）。
   *
   * 注意以下实测语义（Swoole 6.2 taskwait）：
   * - 处理器无任何返回值或 return null：Swoole 视为"未投递结果"，
   *   本方法将一直阻塞到超时才返回 false（性能陷阱：任务可能早已完成，
   *   但调用方仍需干等整个超时时长）。
   * - return false / finish(false)：立即返回 false，与超时返回的 false
   *   无法从值上区分。
   * - finish(null)：null 作为真实结果立即送达（与 return null 不同）。
   * - 超时或投递失败：返回 false。
   * 因此处理器应显式回传结果；需表达"业务失败"时，建议回传结构化数据
   * （如 ['ok' => false]）而不是裸 false/null。
   *
   * @param string $topic 已注册的任务主题名称
   * @param mixed $data 传递给任务处理器的业务数据
   * @param float $timeout 等待超时时间，单位秒，默认 0.5
   * @return mixed|false 处理器回传的原始结果（类型原样保留）；处理器无返回值/return null 时阻塞满超时后返回 false；return false 或超时/投递失败返回 false
   */
  public function emitWait(
    string $topic,
    mixed  $data,
    float  $timeout = 0.5
  ): mixed {
    $this->has($topic);
    $data = [
      'data' => $data,
      'topic' => $topic,
      'queueId' => null,
      'dispatch_time' => null,
    ];
    return Server::getServer()->taskwait($data, $timeout);
  }

  /**
   * 同步阻塞投递多个任务并等待全部执行结果
   *
   * @param array<string,array> $tasks 任务列表，键为主题名称，值为传递给处理器的业务数据
   * @param float $timeout 等待超时时间，单位秒，默认 0.5
   * @param bool $isCo 是否启用协程并发调度，启用时使用 taskCo，否则使用 taskWaitMulti
   * @return array 各任务的执行结果列表
   */
  public function emitsWait(
    array $tasks,
    float $timeout = 0.5,
    bool  $isCo = false
  ): array {
    $topics = [];
    foreach ($tasks as $topic => $data) {
      $this->has($topic);
      $topics[] = [
        'data' => $data,
        'topic' => $topic,
        'queueId' => null,
        'dispatch_time' => null,
      ];
    }
    if ($isCo) {
      return Server::getServer()->taskCo($topics, $timeout);
    } else {
      return Server::getServer()->taskWaitMulti($topics, $timeout);
    }
  }
}
