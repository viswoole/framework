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

namespace Viswoole\Core\Server;

use Swoole\Process;
use Viswoole\Core\App;
use Viswoole\Core\Console\Output;
use Viswoole\Core\Exception\ServerException;
use Viswoole\Core\Server;

/**
 * Swoole 服务器操作类
 *
 * 提供服务的启动、停止、重启和状态查询等命令行操作，
 * 通过 PID 文件跟踪服务进程状态，支持强制关闭和安全停止。
 */
class Action
{
  /**
   * 启动服务，支持强制重启和进程守护模式
   *
   * @param string $server_name 服务名称
   * @param bool $forceStart 是否强制启动（自动关闭已有进程）
   * @param bool $daemonize 是否以守护进程方式运行
   * @throws ServerException 服务已在运行或强制关闭失败时抛出
   */
  public static function start(
    string $server_name,
    bool   $forceStart = false,
    bool   $daemonize = false
  ): void
  {
    $pid = self::getServerPid($server_name);
    if (self::checkPidStatus($pid)) {
      if ($forceStart) {
        Output::system("{$server_name}服务正在运行中，正在尝试关闭，请等待...", 'WARNING');
        // 修复: 强制重启复用统一的「发送信号→等待退出→超时强杀→清理PID」流程，
        // 替代原先固定 5 轮 SIGINT+sleep 的盲试
        if (self::stopAndWait($pid, $server_name, 5)) {
          App::factory()->make('server', [$server_name])->start($daemonize);
        } else {
          throw new ServerException(
            "⚠️ {$server_name}服务强制重启失败，无法kill {$pid}进程，请手动kill进程。"
          );
        }
      } else {
        throw new ServerException("⚠️ {$server_name}服务正在运行中，请勿重复启动。");
      }
    } else {
      App::factory()->make('server', [$server_name])->start($daemonize);
    }
  }

  /**
   * 获取服务进程 PID，通过读取 PID 文件并验证进程是否存活
   *
   * @param string $server_name 服务名称
   * @return false|int 进程 PID，服务未运行返回 false
   */
  public static function getServerPid(string $server_name): false|int
  {
    // 服务名会拼接为 PID 文件路径，必须限制为安全字符，
    // 防止穿越名把任意文件内容当作 PID 读取后向任意进程发送信号
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $server_name)) {
      throw new ServerException("非法的服务名称：{$server_name}，仅允许字母、数字、下划线和中划线");
    }
    $pid_dir = self::getPidStore($server_name);
    $pid_file = $pid_dir . "/$server_name.pid";
    //读取服务进程id 判断服务是否正在运行
    $pid = null;
    $status = false;
    if (is_file($pid_file)) {
      // 获取PID内容
      $file_content = @file_get_contents($pid_file);
      if (!empty($file_content)) {
        $pid = (int)$file_content;
        // 判断进程是否正在运行；
        // 修复: 仅验存活会被 PID 复用误判——容器内 PID 回收后其他进程占号，
        // 导致 start 报「服务正在运行中」而实际服务已死。追加 cmdline 匹配
        // 本框架入口（/proc 不可用时退回仅存活校验）
        $status = Process::kill($pid, 0) && self::isViswooleProcess($pid);
        // 如果没有运行则删除pid文件
        if (!$status) unlink($pid_file);
      }
    }
    return $status ? $pid : false;
  }

  /**
   * 判断指定 PID 的进程是否为本框架服务进程
   *
   * 读取 /proc/{pid}/cmdline 匹配 viswoole 入口关键词（NUL 分隔符归一为空格）；
   * /proc 不可用（非 Linux / 权限受限）时保守返回 true，退回仅存活校验的旧行为
   *
   * @param int $pid 进程ID
   * @return bool 匹配本框架进程（或无法判定时）返回 true
   */
  private static function isViswooleProcess(int $pid): bool
  {
    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
    if ($cmdline === false || $cmdline === '') return true;
    return stripos(str_replace("\0", ' ', $cmdline), 'viswoole') !== false;
  }

  /**
   * 停止服务主进程：发送 SIGINT 触发优雅停机，等待退出；超时 SIGKILL 强杀兜底，
   * 结束后清理 PID 文件避免残留影响下次 start 判定
   *
   * @param int $pid 服务主进程 PID
   * @param string $server_name 服务名称（用于定位 PID 文件）
   * @param int $timeout 等待优雅退出的超时秒数
   * @return bool 进程已退出返回 true，发送信号失败返回 false
   */
  private static function stopAndWait(int $pid, string $server_name, int $timeout = 10): bool
  {
    if (!Process::kill($pid, SIGINT)) return false;
    // 修复: 此前发完 SIGINT 即返回，不等待 worker 退出——worker 协程阻塞时
    // 端口仍 LISTEN、请求全部挂起，形成僵死服务
    $waited = 0;
    while ($waited++ < $timeout && Process::kill($pid, 0)) {
      sleep(1);
    }
    if (Process::kill($pid, 0)) {
      // SIGKILL 不可捕获不可忽略，防僵死兜底
      Process::kill($pid, SIGKILL);
      usleep(300000); // 给内核回收进程留出时间
    }
    $pid_file = self::getPidStore($server_name) . "/$server_name.pid";
    if (is_file($pid_file)) @unlink($pid_file);
    return !Process::kill($pid, 0);
  }

  /**
   * 获取 PID 文件存储目录，优先使用服务配置中的路径
   *
   * @param string|null $server_name 服务名称，为 null 时使用默认目录
   * @return string PID 目录绝对路径
   */
  public static function getPidStore(?string $server_name): string
  {
    $pid_dir = null;
    if ($server_name) $pid_dir = config("server.servers.$server_name.options.pid_store_dir");
    if (empty($pid_dir)) $pid_dir = Server::getDefaultPidStoreDir();
    return $pid_dir;
  }

  /**
   * 判断指定 PID 对应的进程是否正在运行
   *
   * @param int|false|string $pid 进程ID
   * @return bool 进程存活返回 true
   */
  private static function checkPidStatus(int|false|string $pid): bool
  {
    if (false === $pid) return false;
    if (is_numeric($pid)) {
      return Process::kill((int)$pid, 0);
    } else {
      return false;
    }
  }

  /**
   * 获取服务运行状态
   *
   * @param string $server_name 服务名称
   * @return bool 服务正在运行返回 true
   */
  public static function getStatus(string $server_name): bool
  {
    return self::checkPidStatus(self::getServerPid($server_name));
  }

  /**
   * 安全停止服务，发送 SIGINT 信号以触发 ServerShuttingDown 事件清理资源
   *
   * 不指定服务名时尝试关闭所有服务
   *
   * @param string|null $server_name 服务名称，为 null 时关闭所有服务
   * @throws ServerException 发送信号失败时抛出
   */
  public static function close(?string $server_name = null): void
  {
    if (empty($server_name)) {
      $pid_dir = self::getPidStore(null);
      $files = glob($pid_dir . '/*.pid');
      if (empty($files)) {
        Output::system('🈚️ 没有找到任何服务进程', 'WARNING');
      } else {
        foreach ($files as $file) {
          $pid = (int)file_get_contents($file);
          $server_name = basename($file, '.pid');
          if (self::checkPidStatus($pid)) {
            // 修复: 发送 SIGINT 后等待服务退出（超时强杀）并清理 PID 文件，
            // 避免发完信号即返回、worker 未退出时端口仍 LISTEN 的僵死态
            Output::system("⏳ 正在停止{$server_name}服务主进程($pid)...");
            if (!self::stopAndWait($pid, $server_name)) {
              throw new ServerException("❌ {$server_name}服务主进程($pid)停止失败，请手动kill进程。");
            }
            Output::system("✅ {$server_name}服务已停止");
          } else {
            // 删除掉无效的pid文件
            unlink($file);
          }
        }
      }
    } else {
      $pid = self::getServerPid($server_name);
      if (self::checkPidStatus($pid)) {
        // 发送SIGINT信号替代掉SIGTERM，
        // 因为无法在内部Process::signal捕获SIGTERM信号触发ServerShuttingDown事件，清理掉资源，如定时器，
        // 所以采用SIGINT信号替代SIGTERM信号，已在服务启动事件中监听了SIGINT，并调用Server::shutdown。
        // 修复: 发送 SIGINT 后等待服务退出（超时强杀）并清理 PID 文件，防僵死
        Output::system("⏳ 正在停止{$server_name}服务主进程($pid)...");
        if (!self::stopAndWait($pid, $server_name)) {
          throw new ServerException("❌ {$server_name}服务主进程($pid)停止失败，请手动kill进程。");
        }
        Output::system("✅ {$server_name}服务已停止");
      } else {
        Output::system("🈚️ {$server_name}服务未运行", 'WARNING');
      }
    }
  }

  /**
   * 重启服务 Worker 进程，SIGUSR1 重启所有 Worker，SIGUSR2 仅重启 Task Worker
   *
   * @param string $server_name 服务名称
   * @param bool $only_reload_task_worker 是否仅重启 Task Worker 进程
   * @throws ServerException 发送信号失败时抛出
   */
  public static function reload(string $server_name, bool $only_reload_task_worker = false): void
  {
    $pid = self::getServerPid($server_name);
    if ($pid) {
      $status = Process::kill($pid, $only_reload_task_worker ? SIGUSR2 : SIGUSR1);
      if (!$status) {
        throw new ServerException("❌ {$server_name}服务重启失败");
      }
    }
  }
}
