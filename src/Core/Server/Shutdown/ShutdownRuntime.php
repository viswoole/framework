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

use Closure;
use Swoole\Atomic;
use Swoole\Server;
use Throwable;
use Viswoole\Core\Exception\ServerException;

/**
 * fork 前分配共享计数，在停机后保存回执；跟踪已进入 Task 和请求回调的工作，不判断业务成败
 */
final class ShutdownRuntime
{
  /**
   * @var Atomic|null 已进入处理器的 Task 总数，fork 前创建供全进程共享
   */
  private static ?Atomic $activeTasks = null;
  /**
   * @var Atomic|null 已进入回调的请求数，fork 前创建供全进程共享
   */
  private static ?Atomic $activeRequests = null;
  /**
   * @var Atomic|null 是否处于停机期间（1 为是），用于暂停恢复重投和新投递
   */
  private static ?Atomic $isStopping = null;
  /**
   * @var string|null 本次运行唯一标识，命令端据此识别回执是否属于本次启动
   */
  private static ?string $runId = null;

  /**
   * @return void 必须在 Swoole fork 前初始化，供 master 和 Task Worker 共享
   */
  public static function prepare(): void
  {
    self::$activeTasks = new Atomic(0);
    self::$activeRequests = new Atomic(0);
    self::$isStopping = new Atomic(0);
    self::$runId = bin2hex(random_bytes(16));
  }

  /**
   * 包装 Task 回调并维护共享计数
   *
   * @param Closure $handler 原事件回调
   * @param array $arguments 事件参数
   * @return mixed 原始返回值
   */
  public static function runTask(Closure $handler, array $arguments): mixed
  {
    return self::runCallback(self::$activeTasks, $handler, $arguments);
  }

  /**
   * 包装请求回调并维护共享计数
   *
   * @param Closure $handler 请求回调
   * @param array $arguments 事件参数
   * @return mixed 原始返回值
   */
  public static function runRequest(Closure $handler, array $arguments): mixed
  {
    return self::runCallback(self::$activeRequests, $handler, $arguments);
  }

  /**
   * 计数包裹回调执行，异常时也必须递减
   *
   * @param Atomic|null $counter 共享计数
   * @param Closure $handler 回调
   * @param array $arguments 参数
   * @return mixed 回调结果
   */
  private static function runCallback(?Atomic $counter, Closure $handler, array $arguments): mixed
  {
    $counter?->add();
    try {
      return $handler(...$arguments);
    } finally {
      $counter?->sub();
    }
  }

  /**
   * @return bool 停止期间暂停恢复重投和新投递
   */
  public static function isStopping(): bool
  {
    return self::$isStopping?->get() === 1;
  }

  /**
   * 落盘运行中回执
   *
   * @param Server $server 原生服务
   * @return void 记录本次实际启动配置，避免命令读取已变更配置
   */
  public static function started(Server $server): void
  {
    self::write($server, 'running');
  }

  /**
   * 标记停机开始并落盘状态
   *
   * @param Server $server 原生服务
   * @return void 在退出通知前关闭新任务和恢复入口
   */
  public static function stopping(Server $server): void
  {
    self::$isStopping?->set(1);
    self::write($server, 'stopping');
  }

  /**
   * 停机结束时落盘最终回执
   *
   * @param Server $server 原生服务
   * @return StopResult 剩余任务意味着 Swoole 退出期间发生了中断
   */
  public static function stopped(Server $server): StopResult
  {
    $hasActiveCallbacks = (self::$activeTasks?->get() ?? 0) + (self::$activeRequests?->get() ?? 0) > 0;
    $result = $hasActiveCallbacks ? StopResult::Forced : StopResult::Graceful;
    return self::write($server, $result->value) ? $result : StopResult::Unconfirmed;
  }

  /**
   * 读取与本次启动匹配的停机回执
   *
   * @param string $pidFile PID 文件路径
   * @param int $pid 本次主进程
   * @return array|null 匹配的运行回执
   */
  public static function read(string $pidFile, int $pid): ?array
  {
    $path = $pidFile . '.shutdown.json';
    if (!is_file($path)) return null;
    try {
      $data = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
      error_log('[Viswoole Shutdown] 停机回执不可用：' . $exception->getMessage());
      return null;
    }
    if (!is_array($data) || ($data['pid'] ?? null) !== $pid) return null;
    $identity = ServerProcessTree::identity($pid);
    if ($identity !== null && ($data['identity'] ?? null) !== $identity) return null;
    return $data;
  }

  /**
   * 原子写入停机回执
   *
   * @param Server $server 原生服务
   * @param string $status 生命周期状态
   * @return bool 是否成功原子替换回执
   */
  private static function write(Server $server, string $status): bool
  {
    $pidFile = $server->setting['pid_file'] ?? null;
    if (!is_string($pidFile) || $pidFile === '') return false;
    $data = ['pid' => $server->getMasterPid(), 'run_id' => self::$runId,
      'identity' => ServerProcessTree::identity($server->getMasterPid()), 'max_wait_time' => $server->setting['max_wait_time'],
      'reload_async' => $server->setting['reload_async'], 'status' => $status,
      'active_tasks' => self::$activeTasks?->get() ?? 0, 'active_requests' => self::$activeRequests?->get() ?? 0];
    $path = $pidFile . '.shutdown.json';
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    try {
      if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR)) === false || !rename($temporary, $path)) {
        throw new ServerException("写入服务停机回执失败：$path");
      }
      return true;
    } catch (Throwable $exception) {
      // 回执不可写时不能阻止实际停机；命令端按“无法确认”报告，而非虚报正常完成。
      error_log('[Viswoole Shutdown] ' . $exception->getMessage());
      return false;
    } finally {
      if (is_file($temporary)) unlink($temporary);
    }
  }
}
