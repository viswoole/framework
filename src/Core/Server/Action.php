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
        $status = false;
        $i = 0;
        Output::system("{$server_name}服务正在运行中，正在尝试关闭，请等待...", 'WARNING');
        while ($i++ < 5) {
          if (!self::checkPidStatus($pid)) {
            $status = true;
            break;
          } else {
            Process::kill($pid, SIGINT);
            sleep(1);
          }
        }
        // 判断是否强制关闭服务成功
        if ($status) {
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
        // 判断进程是否正在运行
        $status = Process::kill($pid, 0);
        // 如果没有运行则删除pid文件
        if (!$status) unlink($pid_file);
      }
    }
    return $status ? $pid : false;
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
          $pid = file_get_contents($file);
          if (self::checkPidStatus($pid)) {
            $status = Process::kill((int)$pid, SIGINT);
            $server_name = basename($file, '.pid');
            if (!$status) {
              throw new ServerException("❌ 向{$server_name}服务主进程($pid)发送SIGINT信号失败");
            } else {
              Output::system("✅ 向{$server_name}服务主进程($pid)发送SIGINT信号成功");
            }
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
        $status = Process::kill($pid, SIGINT);
        if (!$status) {
          throw new ServerException("❌ 向{$server_name}服务主进程($pid)发送SIGINT信号失败");
        } else {
          Output::system("✅ 向{$server_name}服务主进程($pid)发送SIGINT信号成功");
        }
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
