<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Core\Server\Shutdown;

use Swoole\Process;

/**
 * 统一普通停止与强制重启的等待、强杀及 PID 清理流程，不输出 CLI 消息
 */
final class ServiceStopper
{
  /**
   * 先让 Swoole 完整执行优雅退出；预算耗尽后才强杀服务进程树。
   * @param int $pid 已通过服务身份校验的主进程
   * @param string $pidFile PID 文件路径
   * @param ShutdownPolicy $fallback 旧服务没有运行回执时的配置预算
   * @return StopResult 停止类型；失败时保留 PID 文件
   */
  public static function stop(int $pid, string $pidFile, ShutdownPolicy $fallback): StopResult
  {
    $record = ShutdownRuntime::read($pidFile, $pid);
    $policy = $record === null ? $fallback : ShutdownPolicy::fromOptions($record);
    $tree = new ServerProcessTree($pid);
    if (!Process::kill($pid, SIGINT)) return StopResult::Failed;
    if (self::waitForExit($tree, $policy->waitSeconds())) {
      $result = self::completedResult($pidFile, $pid, $record['run_id'] ?? null);
    } else {
      if (!$tree->forceKill() || !self::waitForExit($tree, 2)) return StopResult::Failed;
      $result = StopResult::Forced;
    }
    // 只有整棵已跟踪进程树退出才清理，且不能删掉新实例已替换的 PID 文件。
    if (is_file($pidFile) && trim((string)file_get_contents($pidFile)) === (string)$pid) unlink($pidFile);
    return $result;
  }

  /**
   * 轮询等待进程树退出
   *
   * @param ServerProcessTree $tree 本次服务进程树
   * @param int $seconds 最大等待秒数
   * @return bool 已退出
   */
  private static function waitForExit(ServerProcessTree $tree, int $seconds): bool
  {
    $deadline = hrtime(true) + $seconds * 1_000_000_000;
    while ($tree->isAlive()) {
      if (hrtime(true) >= $deadline) return false;
      usleep(100000);
    }
    return true;
  }

  /**
   * 从落盘回执确认停止结果，无法确认时如实报告
   *
   * @param string $pidFile PID 文件路径
   * @param int $pid 原服务主进程
   * @param string|null $runId 本次运行标识
   * @return StopResult 读取已落盘的结果
   */
  private static function completedResult(string $pidFile, int $pid, ?string $runId): StopResult
  {
    if ($runId === null) return StopResult::Unconfirmed;
    $record = ShutdownRuntime::read($pidFile, $pid);
    if (($record['run_id'] ?? null) !== $runId) return StopResult::Unconfirmed;
    return StopResult::tryFrom($record['status'] ?? '') ?? StopResult::Unconfirmed;
  }
}
