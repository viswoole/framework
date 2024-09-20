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

namespace Viswoole\Core\Facade;

use Closure;
use Override;
use Socket;
use Swoole\Process;
use Swoole\Server\Port;
use Viswoole\Core\Facade;

/**
 * Swoole服务管理
 *
 * @link https://wiki.swoole.com/zh-cn/#/server/methods
 *
 * @method static array getConfig() 获取服务配置
 * @method static string getName() 获取当前运行的服务名称
 * @method static \Swoole\Server getServer() 获取\Swoole\Server服务实例
 * @method static bool isStart() 判断服务是否启动
 * @method static Port|false listen(string $host, int $port, int $sock_type)
 * @method static Port|false addlistener(string $host, int $port, int $sock_type)
 * @method static bool on(string $event_name, callable $callback)
 * @method static Closure|array|string|null getCallback(string $event_name)
 * @method static bool set(array $settings)
 * @method static bool start() 启动服务
 * @method static bool send(string|int $fd, string $send_data, int $serverSocket = -1)
 * @method static bool sendto(string $ip, int $port, string $send_data, int $server_socket = -1)
 * @method static bool sendwait(int $conn_fd, string $send_data)
 * @method static bool exists(int $fd)
 * @method static bool exist(int $fd)
 * @method static bool protect(int $fd, bool $is_protected = true)
 * @method static bool sendfile(int $conn_fd, string $filename, int $offset = 0, int $length = 0)
 * @method static bool close(int $fd, bool $reset = false)
 * @method static bool confirm(int $fd)
 * @method static bool pause(int $fd)
 * @method static bool resume(int $fd)
 * @method static int|false task(mixed $data, int $taskWorkerIndex = -1, ?callable $finishCallback = null)
 * @method static mixed taskwait(mixed $data, float $timeout = 0.5, int $taskWorkerIndex = -1)
 * @method static array|false taskWaitMulti(array $tasks, float $timeout = 0.5)
 * @method static array|false taskCo(array $tasks, float $timeout = 0.5)
 * @method static bool finish(mixed $data)
 * @method static bool reload(bool $only_reload_taskworker = false)
 * @method static bool shutdown()
 * @method static bool stop(int $workerId = -1, bool $waitEvent = false)
 * @method static int getLastError()
 * @method static array|false heartbeat(bool $ifCloseConnection = true)
 * @method static array|false getClientInfo(int $fd, int $reactor_id = -1, bool $ignoreError = false)
 * @method static array|false getClientList(int $start_fd = 0, int $find_count = 10)
 * @method static int|false getWorkerId()
 * @method static int|false getWorkerPid(int $worker_id = -1)
 * @method static int|false getWorkerStatus(int $worker_id = -1)
 * @method static int getManagerPid()
 * @method static int getMasterPid()
 * @method static array|false connection_info(int $fd, int $reactor_id = -1, bool $ignoreError = false)
 * @method static array|false connection_list(int $start_fd = 0, int $find_count = 10)
 * @method static bool sendMessage(mixed $message, int $dst_worker_id)
 * @method static array|string|false command(string $name, int $process_id, int $process_type, mixed $data, bool $json_decode = true)
 * @method static bool addCommand(string $name, int $accepted_process_types, callable $callback)
 * @method static int addProcess(Process $process)
 * @method static array stats()
 * @method static Socket|false getSocket(int $port = 0)
 * @method static bool bind(int $fd, int $uid)
 *
 * @see \Viswoole\Core\Server
 */
class Server extends Facade
{

  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Core\Server::class;
  }
}
