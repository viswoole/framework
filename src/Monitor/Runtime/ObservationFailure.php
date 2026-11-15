<?php

declare(strict_types=1);

/* 职责：对运行态观测失败限频告警，不暴露异常消息中的业务信息。 */

namespace Viswoole\Monitor\Runtime;

use Throwable;

/** 每个进程最多每分钟一次，不改变正常业务结果。 */
final class ObservationFailure
{
  private static int $lastReportedAt = 0;

  /**
   * 安全记录动态观测失败
   * @param Throwable $error 原始错误
   * @return void
   */
  public static function report(Throwable $error): void
  {
    $now = time();
    if ($now - self::$lastReportedAt < 60) { return; }
    self::$lastReportedAt = $now;
    trigger_error('动态采集策略读取失败: ' . $error::class, E_USER_WARNING);
  }
}
