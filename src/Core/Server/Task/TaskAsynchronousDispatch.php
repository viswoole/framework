<?php

/*
 * 职责：向生产者报告受理结果；首次 IPC 和补偿失败不改变已经确认的受理事实。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use Throwable;
use Viswoole\Core\Facade\Server;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;

/**
 * 持久化返回队列 ID，直投保持 Swoole 任务 ID；拒收与提交未知分别表达。
 */
trait TaskAsynchronousDispatch
{
  /**
   * 受理异步任务
   *
   * 持久化成功后由框架负责恢复，首次 IPC 失败不能使调用方误判未受理。
   * 非持久化投递保持原生返回值和异常语义。
   *
   * @param string $topic 已注册主题
   * @param mixed $data 业务载荷
   * @param bool $queue 是否持久化
   * @return string|int|false 持久化受理返回队列 ID；直投返回 Swoole ID；停机或直投拒收返回 false
   * @throws TaskAcceptanceUnknownException 提交结果未知，禁止盲目补发
   * @throws Throwable 提交前准备失败、明确拒绝或非持久化 IPC 异常
   */
  public function emit(string $topic, mixed $data, bool $queue = true): string|int|false
  {
    if (ShutdownRuntime::isStopping()) { return false; }
    $this->has($topic);
    $taskData = [
      'data' => $data,
      'topic' => $topic,
      'queueId' => null,
      'dispatch_time' => microtime(true),
      'expire' => $this->resolveExpire($topic),
    ];
    if (!$queue) { return Server::getServer()->task($taskData); }
    $taskQueue = $this->taskQueue();
    $queueId = $taskQueue->enqueue($taskData, $this->instanceId . ':' . Server::getWorkerId());
    // 提交已获得可靠回执；后续任何失败只能影响首次投递，不能改报未受理。
    $this->dispatchAccepted($taskQueue, $queueId);
    return $queueId;
  }

  /**
   * 尽力投递已受理任务
   *
   * 信封读取或补偿写入不可用时保留原投递租约，由到期扫描接管。
   *
   * @param TaskQueue $queue 持久化队列
   * @param string $queueId 已确认受理的身份
   * @return void 首次投递已尝试，恢复责任仍归框架
   */
  private function dispatchAccepted(TaskQueue $queue, string $queueId): void
  {
    try {
      $delivery = $queue->delivery($queueId);
      try {
        if (Server::getServer()->task($delivery) !== false) { return; }
        $reason = 'Swoole 首次投递失败';
      } catch (Throwable $exception) {
        $reason = $exception::class . ': ' . $exception->getMessage();
      }
      $queue->deliveryFailed($delivery, $reason);
      TaskQueueLog::write('任务已受理，等待恢复投递：' . $queueId);
    } catch (Throwable $exception) {
      TaskQueueLog::write('任务已受理，首次投递未完成：' . $queueId, [
        'exception' => (string)$exception,
      ]);
    }
  }
}
