<?php

/*
 * 职责：声明队列查询允许的持久化状态，不包含已 ACK 删除的任务。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

/**
 * 任务持久化生命周期状态。
 */
enum TaskQueueState: string
{
  /**
   * 等待首次或恢复投递消费。
   */
  case Dispatched = 'dispatched';
  /**
   * 已认领，是否仍有效须同时检查租约。
   */
  case Running = 'running';
  /**
   * 等待退避后重试。
   */
  case Retry = 'retry';
  /**
   * 已停止自动重试。
   */
  case Failed = 'failed';
}
