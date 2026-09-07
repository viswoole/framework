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
use Swoole\Server;
use Viswoole\Core\Console\Output;
use Viswoole\Core\Facade\Event;
use Viswoole\Core\FrameworkEvent;

/**
 * Swoole 服务端事件钩子，统一管理服务生命周期事件的注册与分发
 */
class ServerEventHook
{
  /**
   * @var array<string,callable[]> 已注册的事件处理器列表，键为事件名（小写），值为回调数组
   */
  protected static array $handles = [
    'start' => [[ServerEventHook::class, 'onStart']],
    'shutdown' => [[ServerEventHook::class, 'onShutdown']],
    'beforeshutdown' => [[ServerEventHook::class, 'onBeforeShutdown']],
  ];

  /**
   * 批量注册事件处理器
   *
   * @param array<string,callable> $events 事件名称与回调的映射，键为事件名
   */
  public static function addEvents(array $events): void
  {
    $events = array_change_key_case($events);
    foreach ($events as $event => $callback) {
      self::addEvent($event, $callback);
    }
  }

  /**
   * 注册单个事件处理器，同一事件可注册多个回调
   *
   * @param string $event 事件名称，不区分大小写
   * @param callable $callback 事件触发时执行的回调函数
   */
  public static function addEvent(string $event, callable $callback): void
  {
    self::$handles[strtolower(trim($event))][] = $callback;
  }

  /**
   * 获取所有已注册事件的闭包列表，供 Swoole Server 注册事件时使用
   *
   * @return array<string,callable> 事件名到调度闭包的映射
   */
  public static function getEventHooks(): array
  {
    $events = [];
    foreach (array_keys(self::$handles) as $event) {
      $events[$event] = function (mixed ...$args) use ($event) {
        // 透传处理器返回值：Swoole 事件中仅 onTask 的返回值有语义（任务结果回传 Worker 进程）
        return self::dispatch($event, $args);
      };
    }
    return $events;
  }

  /**
   * 依次调用指定事件的所有已注册处理器
   *
   * 返回最后一个处理器的返回值。Swoole 全部服务端事件中仅 onTask 的
   * 返回值有语义（将任务结果回传给 Worker 进程并触发 onFinish），
   * 其余事件回调的返回值 Swoole 一律忽略；因此调度链路必须透传返回值，
   * 同一事件注册多个处理器时，仅最后一个处理器的返回值生效。
   *
   * @param string $event 事件名称
   * @param array $args 传递给事件处理器的参数列表
   * @return mixed 最后一个处理器的返回值，无处理器时返回 null
   */
  private static function dispatch(string $event, array $args): mixed
  {
    $handlers = self::$handles[$event] ?? null;
    $result = null;
    if (!is_null($handlers)) {
      foreach ($handlers as $handler) {
        $result = invoke($handler, $args);
      }
    }
    return $result;
  }

  /**
   * 服务关闭前回调，触发 ServerShuttingDown 事件以允许执行清理工作
   *
   * @param Server $server Swoole 服务实例
   */
  private static function onBeforeShutdown(Server $server): void
  {
    // 触发服务关闭前事件，允许用户在服务关闭前执行一些清理工作
    Event::emit(FrameworkEvent::ServerShuttingDown, [$server]);
  }

  /**
   * 服务关闭回调，输出服务已安全关闭的提示信息
   *
   * @param Server $server Swoole 服务实例
   */
  private static function onShutdown(Server $server): void
  {
    // 主进程id
    $pid = $server->getMasterPid();
    // 服务名称
    $SERVER_NAME = strtoupper(SERVER_NAME);
    echo_log(
      "🚫 服务已安全关闭($pid)",
      $SERVER_NAME,
      color    : Output::LABEL_COLOR['DEBUG'],
      backtrace: 0
    );
  }

  /**
   * 服务启动回调，输出启动信息、监听 SIGINT 信号以安全关闭服务，并触发 ServerStarted 事件
   *
   * @param Server $server Swoole 服务实例
   */
  private static function onStart(Server $server): void
  {
    $pid = $server->getMasterPid();
    // 服务名称
    $SERVER_NAME = strtoupper(SERVER_NAME);
    echo_log(
      "🚀 服务已启动，正在运行...($pid)",
      $SERVER_NAME,
      color    : Output::LABEL_COLOR['SUCCESS'],
      backtrace: 0
    );
    // 监听SIGINT信号，将服务安全关闭，以释放资源
    Process::signal(SIGINT, function () use ($server, $SERVER_NAME, $pid) {
      echo_log(
        "🛑 捕获到中断信号SIGINT，正在释放资源...($pid)",
        $SERVER_NAME,
        color    : Output::LABEL_COLOR['WARNING'],
        backtrace: 0
      );
      // 关闭服务
      $result = $server->shutdown();
      if (!$result) {
        echo_log(
          "❌ 服务关闭失败，请检查服务状态！($pid)",
          $SERVER_NAME,
          color    : Output::LABEL_COLOR['ERROR'],
          backtrace: 0
        );
      }
    });
    Event::emit(FrameworkEvent::ServerStarted, [$server]);
  }
}
