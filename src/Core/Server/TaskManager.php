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
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Core\Coroutine;
use Viswoole\Core\Facade\Server;
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
  const string CACHE_TAG_PREFIX = '$_TASK_QUEUE_';
  /**
   * @var array<string,callable> 任务主题
   */
  protected array $topics = [];

  /**
   * @param CacheManager $cache 缓存管理器，用于任务队列的持久化存储
   */
  public function __construct(protected CacheManager $cache)
  {
    // 监听启动事件，恢复未完成的任务
    ServerEventHook::addEvent('workerStart', function (SwooleServer $server, int $workerId) {
      if (!$server->taskworker) {
        $store = $this->getQueueCacheStore((string)$workerId);
        $taskQueue = $store->get();
        foreach ($taskQueue as $queueId) {
          go(function () use ($queueId, $store) {
            $taskData = $this->cache->get($queueId);
            if ($taskData) {
              $result = Server::getServer()->task($taskData);
              if (!$result) Log::task("队列任务恢复失败：$queueId", $taskData);
            }
          });
        }
      }
    });
    ServerEventHook::addEvent('task', function (SwooleServer $server, SwooleTask $task) {
      $this->onTask($server, $task);
    });
  }

  /**
   * 获取指定工作进程对应的队列缓存存储
   *
   * @param string $workId 工作进程ID
   * @return CacheTagInterface 带标签的缓存存储实例
   */
  protected function getQueueCacheStore(string $workId): CacheTagInterface
  {
    return $this->cache->tag(self::CACHE_TAG_PREFIX . $workId);
  }

  /**
   * 处理 Swoole onTask 事件，将任务分发到对应主题的处理器
   *
   * @param SwooleServer $server Swoole 服务实例
   * @param SwooleTask $task Swoole 任务对象
   */
  protected function onTask(SwooleServer $server, SwooleTask $task): void
  {
    $topic = $task->data['topic'] ?? null;
    if (empty($topic)) {
      throw new InvalidArgumentException('必须使用该类中的方法触发任务');
    }
    self::has($task->data['topic']);
    $taskProxy = new TaskProxy($task);
    if ($taskProxy->queue_id) {
      // 队列语义为待消费队列（at-most-once）：任务被 Task Worker 消费即删除，
      // 与执行结果无关（finish 仅向 Worker 进程回传结果，不代表任务成功）。
      // 崩溃恢复只覆盖"已投递但未消费"的窗口。
      self::remove($taskProxy->queue_id, (string)$taskProxy->worker_id);
    }
    $handle = $this->topics[strtolower($topic)];
    try {
      call_user_func_array($handle, [$taskProxy, $server]);
    } catch (Throwable $e) {
      // 任务异常仅记录到任务日志通道，队列条目已在消费时删除，不会重投
      Log::task("任务执行异常：$taskProxy->topic($taskProxy->queue_id)", [
        'exception' => (string)$e,
        'data' => $taskProxy->data,
      ]);
    }
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
   * 从队列缓存中移除已完成的任务记录
   *
   * @param string $queueId 队列唯一标识
   * @param string $workId 工作进程ID
   */
  protected function remove(string $queueId, string $workId): void
  {
    self::getQueueCacheStore($workId)->remove($queueId);
  }

  /**
   * 投递异步任务到 Task Worker 进程
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
  ): int|false
  {
    self::has($topic);
    $taskData = [
      'data' => $data,
      'topic' => $topic,
      'queueId' => null,
      'dispatch_time' => null,
    ];
    if ($queue) {
      // 获取当前workerId
      $workId = (string)Server::getWorkerId();
      // 获取当前协程id
      $cid = (string)Coroutine::id();
      // 生成一个唯一队列id
      $queueId = $workId . '_' . md5(uniqid("$workId:$cid:$topic"));
      $taskData['queueId'] = $queueId;
      $taskData['dispatch_time'] = microtime(true);
      // 缓存商店
      $store = $this->getQueueCacheStore($workId);
      // 缓存结果
      $cacheResult = $store->set($queueId, $taskData, 0);
      if ($cacheResult) {
        $result = Server::getServer()->task($taskData);
        // 如果投递任务失败则删除缓存
        if (!$result) $this->remove($queueId, $workId);
        return $result;
      } else {
        throw new RuntimeException('将任务数据写入到缓存队列失败！');
      }
    } else {
      return Server::getServer()->task($taskData);
    }
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
   * @param string $topic 已注册的任务主题名称
   * @param mixed $data 传递给任务处理器的业务数据
   * @param float $timeout 等待超时时间，单位秒，默认 0.5
   * @return string|false 任务执行成功返回结果字符串，失败或回调返回 null 时返回 false
   */
  public function emitWait(
    string $topic,
    mixed  $data,
    float  $timeout = 0.5
  ): string|false
  {
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
  ): array
  {
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
