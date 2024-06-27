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
use ViSwoole\Core\Console\Output;
use Viswoole\Core\Exception\ServerException;

/**
 * Swoole服务操作类
 */
class Action
{
  /**
   * 启动服务
   *
   * @param string $server_name 服务名称
   * @param bool $forceStart 强制启动，自动关闭正在运行的进程
   * @param bool $daemonize 进程守护后台运行
   * @return bool
   * @throws ServerException
   */
  public static function start(
    string $server_name,
    bool   $forceStart = false,
    bool   $daemonize = false
  ): bool
  {
    $pid = self::getServerPid($server_name);
    if ($pid) {
      if ($forceStart) {
        $i = 0;
        while ($i++ <= 5) {
          $pid = self::getServerPid($server_name);
          if (!$pid) {
            return App::factory()->make('server', [$server_name])->start($daemonize);
          } else {
            Process::kill($pid, SIGTERM);
            sleep(1);
          }
        }
        throw new ServerException("{$server_name}服务强制重启失败。");
      } else {
        throw new ServerException("{$server_name}服务已经在运行中，请勿重复启动。");
      }
    } else {
      return App::factory()->make('server', [$server_name])->start($daemonize);
    }
  }

  /**
   * 获取服务进程PID
   *
   * @access public
   * @param string $server_name 服务名称
   * @return false|int 如果未运行返回false
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
   * 获取PID存储目录
   *
   * @param string|null $server_name
   * @return string
   */
  public static function getPidStore(?string $server_name): string
  {
    $pid_dir = null;
    if ($server_name) $pid_dir = config("server.servers.$server_name.options.pid_store_dir");
    if (empty($pid_dir)) {
      $pid_dir = config(
        'server.default_pid_store_dir',
        getRootPath() . '/runtime/server_pid'
      );
    }
    return $pid_dir;
  }

  /**
   * 获取服务状态
   *
   * @access public
   * @param string $server_name 服务名称
   * @return bool
   */
  public static function getStatus(string $server_name): bool
  {
    return is_int(self::getServerPid($server_name));
  }

  /**
   * 安全停止服务
   *
   * @access public
   * @param string|null $server_name
   * @return void
   */
  public static function close(string $server_name = null): void
  {
    if (empty($server_name)) {
      $pid_dir = self::getPidStore(null);
      $files = glob($pid_dir . '/*.pid');
      foreach ($files as $file) {
        $pid = file_get_contents($file);
        $status = Process::kill((int)$pid, SIGTERM);
        $server_name = basename($file, '.pid');
        if (!$status) {
          Output::error("向{$server_name}服务进程($pid)发送SIGTERM信号失败", 0);
        } else {
          Output::success("向{$server_name}服务进程($pid)发送SIGTERM信号成功", 0);
        }
      }
    } else {
      $pid = self::getServerPid($server_name);
      if ($pid) {
        $status = Process::kill($pid, SIGTERM);
        if (!$status) {
          Output::error("向{$server_name}服务进程($pid)发送SIGTERM信号失败", 0);
        } else {
          Output::success("向{$server_name}服务进程($pid)发送SIGTERM信号成功", 0);
        }
      }
    }
  }

  /**
   * 重启服务
   *
   * @access public
   * @param string $server_name
   * @param bool $only_reload_task_worker 是否只重启任务进程
   * @return void
   * @throws ServerException
   */
  public static function reload(string $server_name, mixed $only_reload_task_worker = false): void
  {
    $pid = self::getServerPid($server_name);
    if ($pid) {
      $status = Process::kill($pid, $only_reload_task_worker ? SIGUSR2 : SIGUSR1);
      if (!$status) throw new ServerException("{$server_name}服务重启失败");
    }
  }
}
