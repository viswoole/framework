<?php

declare(strict_types=1);

/* 职责：按本机容量从共享队列领取并投给本机 Task Worker，不选择远端节点或执行处理器。 */

namespace Viswoole\Core\Server\Task;

use InvalidArgumentException;
use Swoole\Server as SwooleServer;
use Throwable;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;

/**
 * 所有节点使用同一套正常领取与故障恢复入口
 */
trait TaskClusterDispatch
{
  /**
   * @var bool 当前 Worker 是否有尚未结束的领取轮次
   */
  private bool $isQueueDispatching = false;
  /**
   * @var bool 并发预算配置校验失败后停用本进程领取，避免每轮重复记日志
   */
  private bool $isCapacityConfigInvalid = false;
  /**
   * @var float 当前 Worker 下一次允许查询共享队列的时间
   */
  private float $nextQueueScanAt = 0;

  /**
   * 注册本机队列消费调度
   *
   * 每节点仅 HTTP Worker 0 推动领取，Task Worker 不建立重复扫描器。
   *
   * @param SwooleServer $server 原生服务
   * @param int $workerId 当前 Worker 身份
   * @return void 领取定时器已尝试注册
   */
  protected function startQueueRecovery(SwooleServer $server, int $workerId): void
  {
    if ($server->taskworker || $workerId !== 0) { return; }
    try {
      if (!TaskQueue::isSupported($this->queueStore())) { return; }
      $this->taskQueue();
    } catch (Throwable $error) {
      TaskQueueLog::write('任务队列缓存商店不可用，恢复扫描已跳过', ['exception' => (string)$error]);
      return;
    }
    $recover = function () use ($server): void { $this->recoverTasks($server); };
    $recover();
    // 有在途任务时每100ms检查容量，空闲时在内存中节流为1秒，不做生产节点优先直投。
    TaskQueueRecoveryTimer::start(100, $recover);
  }

  /**
   * 领取本机能够承接的任务
   *
   * 重入保护避免 Redis 协程挂起时并发扫描；容量最终由任务状态提交脚本原子复核。
   *
   * @param SwooleServer $server 本机投递入口
   * @return void 有界轮次已结束
   */
  protected function recoverTasks(SwooleServer $server): void
  {
    if ($this->isQueueDispatching || $this->isCapacityConfigInvalid
      || ShutdownRuntime::isStopping() || microtime(true) < $this->nextQueueScanAt) { return; }
    $this->isQueueDispatching = true;
    $this->nextQueueScanAt = microtime(true) + 1;
    try {
      $capacity = $this->queueCapacity($server);
      if ($capacity === 0) { return; }
      $queue = $this->taskQueue();
      $active = $queue->assignedCount($this->instanceId);
      $processed = $queue->recover(fn (array $data): int|false => $this->dispatchLocally($server, $data), [
        'instanceId' => $this->instanceId, 'capacity' => $capacity, 'topics' => array_keys($this->topics),
        'canContinue' => static fn (): bool => !ShutdownRuntime::isStopping(),
      ]);
      if ($active > 0 || $processed > 0) { $this->nextQueueScanAt = microtime(true) + 0.1; }
    } catch (InvalidArgumentException $error) {
      // 配置错误无法自行恢复，记录一次并停用本进程领取，避免周期日志洪泛。
      $this->isCapacityConfigInvalid = true;
      TaskQueueLog::write('任务并发预算配置无效，本进程领取已停用', ['exception' => (string)$error]);
    } catch (Throwable $error) {
      TaskQueueLog::write('队列恢复异常，将于下个周期重试', ['exception' => (string)$error]);
    } finally {
      $this->isQueueDispatching = false;
    }
  }

  /**
   * 计算本机持久化任务预算
   *
   * 非协程任务按进程数计算，协程任务使用每进程预算汇总为节点上限；本机进程分配交由 Swoole。
   *
   * @param SwooleServer $server 实际运行配置
   * @return int 本机队列任务并发上限，无 Task Worker 时为0
   * @throws InvalidArgumentException 协程预算无效
   */
  private function queueCapacity(SwooleServer $server): int
  {
    $workers = (int)($server->setting['task_worker_num'] ?? 0);
    $value = $this->config->get('task.concurrency_per_worker', 8);
    // 环境变量与部分配置来源产生数字字符串，统一归一化为整数后再校验范围。
    $concurrency = is_numeric($value) ? (int)$value : 0;
    if ($concurrency < 1 || $concurrency > 1024) {
      throw new InvalidArgumentException('任务协程预算必须为1到1024的整数');
    }
    return $workers * (!empty($server->setting['task_enable_coroutine']) ? $concurrency : 1);
  }

  /**
   * 把已分配任务交给本机 Swoole
   *
   * 停机竞争和 IPC 失败仍由队列状态机释放资格、恢复投递，不转发至其他节点。
   *
   * @param SwooleServer $server 本机服务
   * @param array $data 不透明队列信封
   * @return int|false 原生投递结果
   */
  private function dispatchLocally(SwooleServer $server, array $data): int|false
  {
    if (ShutdownRuntime::isStopping()) { return false; }
    try {
      $result = $server->task($data);
      if ($result === false) { TaskQueueLog::write('队列任务本机投递失败：' . $data['queueId'], ['topic' => $data['topic'], 'attempts' => $data['attempts'] ?? 0]); }
      return $result;
    } catch (Throwable $error) {
      TaskQueueLog::write('队列任务本机投递异常：' . $data['queueId'], ['exception' => (string)$error]);
      return false;
    }
  }
}
