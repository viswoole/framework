<?php
/**
 * 职责：携带解析失败的原始队列快照；不执行隔离或记录业务日志。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Exception;

use RuntimeException;
use Throwable;

/**
 * 将格式损坏与连接故障区分，隔离提交必须匹配此异常对应的原始字节。
 */
final class QueueRecordException extends RuntimeException
{
  /**
   * 保存出现解析错误的具体版本。
   * @param string $queueId 读取键对应的任务标识
   * @param string $snapshot 解析前的原始字节
   * @param Throwable $cause 解析异常
   */
  public function __construct(
    /**
     * @var string 任务标识
     */
    public readonly string $queueId,
    /**
     * @var string 原始存储快照，不在日志中输出
     */
    public readonly string $snapshot,
    Throwable $cause
  ) {
    parent::__construct('任务队列记录格式无效：' . $queueId . '；' . $cause->getMessage(), previous: $cause);
  }
}
