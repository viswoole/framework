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

declare (strict_types=1);

namespace Viswoole\Tests\Core\Server;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\Server;
use Viswoole\Core\Server\Action;

/**
 * 服务 PID 文件判定回归测试（PID 复用防护）
 *
 * 修复前缺陷：getServerPid 仅用 Process::kill($pid, 0) 校验进程存活——
 * 容器内 PID 回收后被其他进程占号时误判「服务正在运行中」（实际服务已死），
 * start 被拒、close 发错进程。修复后追加 /proc/{pid}/cmdline 匹配本框架入口，
 * 非 viswoole 进程占号的 PID 视为无效并清理 PID 文件。
 */
class ServerPidReuseTest extends TestCase
{
  /** @var resource[] 测试期间打开的外部进程 */
  private array $processes = [];

  /** 测试专用服务名（getServerPid 仅允许字母数字下划线中划线） */
  private const string TEST_SERVER = 'test_pid_reuse';

  protected function tearDown(): void
  {
    foreach ($this->processes as $proc) {
      proc_terminate($proc);
      proc_close($proc);
    }
    $this->processes = [];
    $pidFile = $this->pidFile();
    if (is_file($pidFile)) @unlink($pidFile);
  }

  /**
   * 测试服务名的 PID 文件路径
   */
  private function pidFile(): string
  {
    return Server::getDefaultPidStoreDir() . '/' . self::TEST_SERVER . '.pid';
  }

  /**
   * 起一个非本框架的外部进程（模拟 PID 复用后占号的其他进程）
   */
  private function spawnForeignProcess(): int
  {
    $proc = proc_open('sleep 30', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    self::assertIsResource($proc, '外部进程创建失败');
    $this->processes[] = $proc;
    $status = proc_get_status($proc);
    return (int)$status['pid'];
  }

  /**
   * PID 文件指向存活的非本框架进程时应判定服务未运行并清理 PID 文件
   * （修复前：仅验存活，误判服务正在运行）
   */
  public function testForeignAlivePidIsCleanedAndReturnsFalse(): void
  {
    $foreignPid = $this->spawnForeignProcess();
    file_put_contents($this->pidFile(), (string)$foreignPid);

    $result = Action::getServerPid(self::TEST_SERVER);

    self::assertFalse($result, '非本框架进程占号的 PID 不应判定为服务运行中');
    self::assertFileDoesNotExist($this->pidFile(), '无效 PID 文件应被清理');
  }

  /**
   * PID 文件指向已死亡进程时应判定服务未运行并清理 PID 文件（回归锁定）
   */
  public function testDeadPidIsCleanedAndReturnsFalse(): void
  {
    file_put_contents($this->pidFile(), '999999999');

    $result = Action::getServerPid(self::TEST_SERVER);

    self::assertFalse($result);
    self::assertFileDoesNotExist($this->pidFile());
  }

  /**
   * PID 文件不存在时返回 false（回归锁定）
   */
  public function testMissingPidFileReturnsFalse(): void
  {
    self::assertFalse(Action::getServerPid(self::TEST_SERVER));
  }
}
