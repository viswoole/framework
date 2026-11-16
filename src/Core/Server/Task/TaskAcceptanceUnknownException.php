<?php

/*
 * 职责：向生产者暴露任务受理结果未知及原队列身份；不自动重建任务。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use RuntimeException;
use Throwable;

/**
 * 提交可能成功，调用方须按业务身份核对，不能按普通投递失败补发。
 */
final class TaskAcceptanceUnknownException extends RuntimeException
{
  /**
   * 保存不确定提交的任务身份
   *
   * 原始存储异常保留在异常链中，队列 ID 缺失也不能证明任务未执行。
   *
   * @param string $queueId 本次尝试使用的稳定队列 ID
   * @param Throwable $previous 原始存储故障
   */
  public function __construct(
    /**
     * @var string 本次提交使用的队列 ID，禁止以新身份盲目重投
     */ public readonly string $queueId,
    Throwable $previous,
  ) {
    parent::__construct('任务受理结果未知，请核对原任务：' . $queueId, 0, $previous);
  }
}
