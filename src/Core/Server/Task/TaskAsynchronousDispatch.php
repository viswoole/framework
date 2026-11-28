<?php

/*
 * 职责：持久化异步任务进入共享就绪队列；非持久化任务仍直接投给本机。
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
   * 持久化成功后进入共享就绪队列，由有容量的节点领取；本入口不发起首次 IPC。
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
    // 受理不绑定执行节点；各节点正常消费与故障恢复共用同一领取路径。
    return $taskQueue->enqueue($taskData, $this->instanceId . ':' . Server::getWorkerId(), true);
  }

}
