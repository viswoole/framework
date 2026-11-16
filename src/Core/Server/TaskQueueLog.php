<?php

declare(strict_types=1);

namespace Viswoole\Core\Server;

use Throwable;
use Viswoole\Log\Facade\Log;

/** 队列兜底日志出口；日志后端故障不能中断启动、恢复扫描或已认领任务的执行。 */
final class TaskQueueLog
{
  /**
   * 写入任务日志，后端故障时降级标准错误并保留原异常。
   * @param string $message 原始错误描述
   * @param array $context 日志上下文
   * @return void
   */
  public static function write(string $message, array $context = []): void
  {
    try {
      Log::task($message, $context);
    } catch (Throwable $failure) {
      $original = $context['exception'] ?? '';
      $detail = is_string($original) ? $original : '';
      error_log("[Viswoole TaskQueue] {$message}；{$detail}；日志通道异常：{$failure->getMessage()}");
    }
  }
}
