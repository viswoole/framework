<?php

/*
 * 职责：区分持久化受理和投递信封读取；不管理消费和恢复状态。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use RuntimeException;
use Viswoole\Cache\Exception\QueueCommitUnknownException;

/**
 * 以原子提交成功为受理边界，后续读取失败不撤销受理事实。
 */
trait TaskQueueSubmission
{
  /**
   * 原子受理任务并返回稳定身份
   *
   * 序列化和参数错误发生在提交前；命令回执丢失使用专用异常表示未知。
   *
   * @param array $data 业务载荷
   * @param string $group 原始实例分组
   * @return string 已确认持久化的队列 ID
   * @throws TaskAcceptanceUnknownException 存储未返回可靠提交结果
   * @throws \Throwable 提交前准备失败或存储明确拒绝
   */
  public function enqueue(array $data, string $group): string
  {
    TaskTopicName::validate($data['topic'] ?? null);
    $data['queueId'] = bin2hex(random_bytes(24));
    $data['queueGroup'] = $group;
    $data['attempts'] = 0;
    $data = $this->lease($data, 'dispatched');
    try {
      if (!$this->commit(null, $data)) { throw new RuntimeException('持久化队列任务失败'); }
    } catch (QueueCommitUnknownException $exception) {
      throw new TaskAcceptanceUnknownException($data['queueId'], $exception);
    }
    return $data['queueId'];
  }

  /**
   * 读取已受理任务的 IPC 信封
   *
   * 读取失败不会撤销任务或其恢复入口。
   *
   * @param string $queueId 已受理任务身份
   * @return array 元数据及原始序列化字节
   * @throws \Throwable 存储读取失败或任务已不存在
   */
  public function delivery(string $queueId): array
  {
    return $this->store->getQueueDelivery($queueId)
      ?? throw new RuntimeException('读取已持久化的任务投递信封失败：' . $queueId);
  }

  /**
   * 受理任务并读取首次投递信封
   *
   * 供低层队列调用方使用；生产者需要受理结果时应分别使用 enqueue 和 delivery。
   *
   * @param array $data 业务载荷
   * @param string $group 原始实例分组
   * @return array 含原始载荷字节的投递信封
   * @throws \Throwable 提交或读取信封失败；读取失败时任务已经受理
   */
  public function persist(array $data, string $group): array
  {
    return $this->delivery($this->enqueue($data, $group));
  }
}
