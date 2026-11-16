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
use Viswoole\Core\Server\Shutdown\ShutdownPolicy;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;
use Viswoole\Core\Server\Shutdown\ServiceStopper;
use Viswoole\Core\Server\Shutdown\StopResult;

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
        self::stopService($pid, $server_name);
        App::factory()->make('server', [$server_name])->start($daemonize);
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
    $pid_file = self::getPidFile($server_name);
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
        $status = self::isViswooleAlive($pid);
        // 如果没有运行则删除pid文件
        if (!$status) unlink($pid_file);
      }
    }
    return $status ? $pid : false;
  }

  /**
   * 判断 PID 是否为存活的本框架服务进程（信号存活 + 非僵尸态 + cmdline 匹配）
   *
   * 三重判定：kill 0 信号存活 → /proc/{pid}/cmdline 非空（空说明进程已退出
   * 且父进程未 wait 的僵尸态，容器内父进程不 reap 时常见，kill 0 对僵尸恒真）
   * → cmdline 匹配 viswoole 入口关键词（NUL 分隔符归一为空格，防 PID 复用
   * 误判）；/proc 不可用（非 Linux / 权限受限）时退回仅存活校验的旧行为
   *
   * @param int $pid 进程ID
   * @return bool 存活的本框架进程返回 true
   */
  private static function isViswooleAlive(int $pid): bool
  {
    if (!Process::kill($pid, 0)) return false;
    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
    if ($cmdline === false) return true;
    if ($cmdline === '') return false;
    return stripos(str_replace("\0", ' ', $cmdline), 'viswoole') !== false;
  }

  /**
   * 普通停止与强制重启必须使用相同的退出预算和消息分类。
   * @param int $pid 主进程 PID
   * @param string $serverName 服务名
   * @return void
   */
  private static function stopService(int $pid, string $serverName): void
  {
    $pidFile = self::getPidFile($serverName);
    $record = ShutdownRuntime::read($pidFile, $pid);
    $options = $record ?? array_merge(
      config('server.options', []),
      config("server.servers.$serverName.options", [])
    );
    $policy = ShutdownPolicy::fromOptions($options);
    Output::system("⏳ 正在停止{$serverName}服务($pid)，等待在途任务退出，命令等待预算 {$policy->waitSeconds()} 秒...", 'INFO');
    $result = ServiceStopper::stop($pid, $pidFile, $policy);
    if ($result === StopResult::Failed) throw new ServerException($result->message($serverName));
    Output::system($result->message($serverName), $result === StopResult::Graceful ? 'SUCCESS' : 'WARNING');
  }

  /** @param string $serverName 服务名 @return string 与启动选项一致的 PID 文件路径 */
  public static function getPidFile(string $serverName): string
  {
    return config("server.servers.$serverName.options.pid_file")
      ?? config('server.options.pid_file')
      ?? self::getPidStore($serverName) . "/$serverName.pid";
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
    if (!empty($server_name)) {
      self::closeNamedService($server_name);
      return;
    }
    $files = glob(self::getPidStore(null) . '/*.pid') ?: [];
    $names = array_unique(array_merge(
      array_keys(config('server.servers', [])),
      array_map(static fn(string $file): string => basename($file, '.pid'), $files)
    ));
    if ($names === []) Output::system('🈚️ 没有找到任何服务进程', 'WARNING');
    foreach ($names as $name) self::closeNamedService($name);
  }

  /** @param string $serverName 服务名 @return void 仅对已通过身份校验的进程发信号 */
  private static function closeNamedService(string $serverName): void
  {
    $pid = self::getServerPid($serverName);
    if ($pid === false) {
      Output::system("🈚️ {$serverName}服务未运行", 'WARNING');
      return;
    }
    self::stopService($pid, $serverName);
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
