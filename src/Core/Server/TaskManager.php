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

declare (strict_types=1);

namespace Viswoole\Core\Server;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Swoole\Server as SwooleServer;
use Swoole\Server\Task as SwooleTask;
use Viswoole\Cache\CacheManager;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Core\Coroutine;
use Viswoole\Core\Facade\Server;
use Viswoole\Log\Facade\Log;

/**
 * 任务管理器
 *
 * 注意：使用任务管理服务必须配置服务选项：
 * `Constant::OPTION_TASK_USE_OBJECT => true` 或 `Constant::OPTION_TASK_ENABLE_COROUTINE => true`
 */
class TaskManager
{
  // 缓存标签前缀
  const string CACHE_TAG_PREFIX = '$_TASK_QUEUE_';
  /**
   * @var array<string,callable> 任务主题
   */
  protected array $topics = [];

  /**
   * @param CacheManager $cache
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
   * 获取队列缓存商店
   *
   * @param string $workId
   * @return CacheTagInterface
   */
  protected function getQueueCacheStore(string $workId): CacheTagInterface
  {
    return $this->cache->tag(self::CACHE_TAG_PREFIX . $workId);
  }

  /**
   * 任务分发，该方法用于处理swoole异步服务的`onTask`事件并分发给对应的任务处理器
   *
   * @param SwooleServer $server
   * @param SwooleTask $task
   * @return void
   */
  protected function onTask(SwooleServer $server, SwooleTask $task): void
  {
    $topic = $task->data['topic'] ?? null;
    if (empty($topic)) {
      throw new InvalidArgumentException('必须使用该类中的方法触发任务');
    }
    self::has($task->data['topic']);
    $taskProxy = new TaskProxy($task, function (string $queueId, string $worker_id) {
      self::remove($queueId, $worker_id);
    });
    $handle = $this->topics[strtolower($topic)];
    call_user_func_array($handle, [$taskProxy, $server]);
    if ($taskProxy->queue_id && !$taskProxy->is_finish) {
      // 从队列中删除事务
      self::remove($taskProxy->queue_id, (string)$taskProxy->worker_id);
    }
  }

  /**
   * 判断是否存在任务主题
   *
   * @param string $topic
   * @return void
   * @throws InvalidArgumentException 不存在则会抛出异常
   */
  public function has(string $topic): void
  {
    $result = isset($this->topics[strtolower($topic)]);
    if (!$result) throw new InvalidArgumentException("没有找到任务主题：$topic");
  }

  /**
   * 从队列中删除任务
   *
   * @param string $queueId 唯一队列id
   * @param string $workId 工作进程id
   * @return void
   */
  protected function remove(string $queueId, string $workId): void
  {
    self::getQueueCacheStore($workId)->remove($queueId);
  }

  /**
   * 异步任务投递
   *
   * @param string $topic 要执行的任务主题
   * @param mixed $data 要传递给任务的数据
   * @param bool $queue 是否加入队列，服务重启能够自动恢复未执行的任务。
   * @return int|false 成功返回任务id，失败返回false
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
   * 添加任务主题
   *
   * 示例：
   *
   * ```
   * $taskManager->dispatch('test', function (TaskProxy $task) {
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
   * $taskManager->dispatch('sms', Sms::class);//将会注册sms.sendLoginCode、sms.sendRegisterCode这两个主题
   * ```
   * @access public
   * @param string $topic 任务名称，不区分大小写。
   * @param callable|string $handle 任务处理函数，支持传入类批量注册。
   * @return void
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
   * 同步阻塞等待任务执行完成
   *
   * @access public
   * @param string $topic 要执行的任务主题
   * @param mixed $data 要传递给任务的数据
   * @param float $timeout 等待超时时间，单位秒
   * @return mixed|false 如果任务执行成功返回任务结果，失败返回false（如果回调函数返回null也会返回false）
   */
  public function emitWait(
    string $topic,
    mixed  $data,
    float  $timeout = 0.5
  ): mixed
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
   * 同步阻塞等待执行多个任务
   *
   * @access public
   * @param array<string,array> $tasks 任务列表[topic=>data]
   * @param float $timeout 超时时间，单位秒
   * @param bool $isCo 是否支持协程调度
   * @return array
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
