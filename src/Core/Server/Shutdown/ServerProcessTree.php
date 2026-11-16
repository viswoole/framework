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
use Viswoole\Core\Exception\ServerException;

/**
 * 跟踪本次服务的进程树及 Linux 启动标识，防止强杀主进程后遗留任务进程或误杀复用 PID
 */
final class ServerProcessTree
{
  /**
   * @var array<int,string|null> PID 到进程启动标识；非 Linux 无 /proc 时仅跟踪主进程
   */
  private array $processes = [];

  /**
   * 从目标主进程出发递归收集进程树
   *
   * @param int $pid 服务主进程 PID
   * @throws ServerException PID 非法或指向当前命令进程时抛出
   */
  public function __construct(int $pid)
  {
    if ($pid <= 1 || $pid === getmypid()) throw new ServerException('拒绝停止 PID 1 或当前命令进程');
    $this->discover($pid);
  }

  /**
   * @return bool 是否仍有原始服务进程存活；僵尸不再运行任务
   */
  public function isAlive(): bool
  {
    $hasAliveProcess = false;
    foreach ($this->processes as $pid => $identity) {
      if (!$this->matches($pid, $identity)) continue;
      $hasAliveProcess = true;
      $this->discover($pid);
    }
    return $hasAliveProcess;
  }

  /**
   * @return bool 强杀所有已确认归属的进程，逐个核对身份以防 PID 复用
   */
  public function forceKill(): bool
  {
    $this->isAlive();
    $isSuccessful = true;
    foreach ($this->processes as $pid => $identity) {
      if (!$this->matches($pid, $identity)) continue;
      if (!Process::kill($pid, SIGKILL) && $this->matches($pid, $identity)) $isSuccessful = false;
    }
    return $isSuccessful;
  }

  /**
   * 读取进程的 Linux 启动标识，用于识别 PID 复用
   *
   * @param int $pid 目标 PID
   * @return string|null Linux 启动时间，非 Linux 返回 null
   */
  public static function identity(int $pid): ?string
  {
    $stat = @file_get_contents("/proc/{$pid}/stat");
    if ($stat === false) return null;
    $fields = explode(' ', substr($stat, (int)strrpos($stat, ')') + 2));
    return $fields[19] ?? null;
  }

  /**
   * 核对进程是否仍为原始归属的存活进程
   *
   * @param int $pid 目标 PID
   * @param string|null $identity 原始身份
   * @return bool 原进程仍在运行
   */
  private function matches(int $pid, ?string $identity): bool
  {
    if (!Process::kill($pid, 0)) return false;
    $stat = @file_get_contents("/proc/{$pid}/stat");
    if ($stat === false) return $identity === null;
    $state = substr($stat, (int)strrpos($stat, ')') + 2, 1);
    return $state !== 'Z' && $state !== 'X' && ($identity === null || self::identity($pid) === $identity);
  }

  /**
   * 递归收集子进程，不覆盖已捕获的进程身份
   *
   * @param int $pid 已确认归属的父进程
   * @return void
   */
  private function discover(int $pid): void
  {
    if (!array_key_exists($pid, $this->processes)) $this->processes[$pid] = self::identity($pid);
    $children = @file_get_contents("/proc/{$pid}/task/{$pid}/children");
    if ($children === false || trim($children) === '') return;
    foreach (preg_split('/\s+/', trim($children)) as $child) {
      $childPid = (int)$child;
      if ($childPid > 1 && !array_key_exists($childPid, $this->processes)) $this->discover($childPid);
    }
  }
}
