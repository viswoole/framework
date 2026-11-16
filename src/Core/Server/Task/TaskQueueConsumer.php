<?php

/**
 * 职责：协调单次消费、续租、ACK 和失败处理；不负责恢复扫描。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use Swoole\Server;
use Swoole\Server\Task;
use Swoole\Timer;
use Throwable;

/**
 * 将业务执行与队列确认绑定，同时保持 Swoole 返回值契约。
 */
final readonly class TaskQueueConsumer
{
  /**
   * 绑定当前进程的队列边界
   *
   * 实例身份来自实际消费者所在进程，不从投递信封推断。
   *
   * @param TaskQueue $queue 当前进程的队列边界
   * @param string|null $instanceId 实际执行实例身份
   */
  public function __construct(
    /**
     * @var TaskQueue 当前进程的队列边界
     */ private TaskQueue $queue,
    /**
     * @var string|null 实际执行实例，低层调用未指定时不推断投递归属
     */ private ?string $instanceId = null
  )
  {
  }

  /**
   * 认领任务并驱动校验、执行与确认的完整消费流程
   * @param Task $task 原始投递
   * @param Server $server 服务实例
   * @param array{validate: callable, handle: callable} $callbacks 主题校验和业务处理
   * @return mixed 处理器结果或失败信号
   * @throws Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function execute(Task $task, Server $server, array $callbacks): mixed
  {
    $lease = $this->queue->consume($task->data, $this->instanceId, $server->worker_id);
    if ($lease === null) { return false; }
    try {
      ($callbacks['validate'])();
    } catch (Throwable $exception) {
      $this->queue->fail($lease, $exception->getMessage(), true);
      return false;
    }
    $timer = $this->startRenewal($lease);
    try {
      $task->data = $this->queue->decodeDelivery($task->data);
      $proxy = new TaskProxy($task, fn (): bool => $this->queue->renew($lease));
      $result = ($callbacks['handle'])($proxy, $server);
      if (!$this->queue->ack($lease)) {
        TaskQueueLog::write('任务完成但 ACK 未提交，租约已失效：' . $lease['queueId']);
        return false;
      }
      return $result;
    } catch (Throwable $exception) {
      $this->recordFailure($lease, $exception);
      return false;
    } finally {
      if ($timer !== false && Timer::exists($timer)) { Timer::clear($timer); }
    }
  }

  /**
   * 启动租约自动续期定时器
   * @param array $lease 当前执行令牌
   * @return int|false 自动续期定时器
   */
  private function startRenewal(array $lease): int|false
  {
    $timer = Timer::tick(
      max(1, (int)($this->queue->leaseSeconds * 1000 / 3)),
      function (int $timerId) use ($lease): void {
        try {
          if (!$this->queue->renew($lease)) {
            Timer::clear($timerId);
            TaskQueueLog::write('任务续租失败，已失去执行权：' . $lease['queueId']);
          }
        } catch (Throwable $exception) {
          TaskQueueLog::write(
            '任务续租异常：' . $lease['queueId'],
            ['exception' => (string)$exception]
          );
        }
      }
    );
    if ($timer === false) { TaskQueueLog::write('任务自动续租定时器创建失败：' . $lease['queueId']); }
    return $timer;
  }

  /**
   * 记录任务执行失败并留痕原始异常
   * @param array $lease 当前执行令牌
   * @param Throwable $exception 原始异常
   * @return void
   */
  private function recordFailure(array $lease, Throwable $exception): void
  {
    try {
      $this->queue->fail($lease, $exception::class . ': ' . $exception->getMessage());
    } catch (Throwable $storageFailure) {
      TaskQueueLog::write(
        '记录任务失败状态异常，等待租约恢复',
        ['exception' => (string)$storageFailure]
      );
    }
    TaskQueueLog::write('任务执行异常：' . $lease['queueId'], ['exception' => (string)$exception]);
  }
}
