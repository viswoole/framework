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

/**
 * SwooleServer事件hook
 */
class ServerEventHook
{
  /**
   * @var array<string,callable[]> 事件处理
   */
  protected static array $handles = [
    'start' => [[ServerEventHook::class, 'onStart']],
    'shutdown' => [[ServerEventHook::class, 'onShutdown']],
    'beforeshutdown' => [[ServerEventHook::class, 'onBeforeShutdown']],
  ];

  /**
   * 批量添加事件处理
   *
   * @param array<string,callable> $events 监听的事件
   * @return void
   */
  public static function addEvents(array $events): void
  {
    $events = array_change_key_case($events);
    foreach ($events as $event => $callback) {
      self::addEvent($event, $callback);
    }
  }

  /**
   * 添加事件处理
   *
   * @param string $event 事件名称
   * @param callable $callback 回调
   * @return void
   */
  public static function addEvent(string $event, callable $callback): void
  {
    self::$handles[strtolower(trim($event))][] = $callback;
  }

  /**
   * 获取需要hook的事件列表，用于注册到swoole服务中(该方法由Server自动调用)
   *
   * @return array<string,callable> 事件列表
   */
  public static function getEventHooks(): array
  {
    $events = [];
    foreach (array_keys(self::$handles) as $event) {
      $events[$event] = function (mixed ...$args) use ($event) {
        self::dispatch($event, $args);
      };
    }
    return $events;
  }

  /**
   * 事件调度
   *
   * @param string $event
   * @param array $args
   * @return void
   */
  private static function dispatch(string $event, array $args): void
  {
    $handlers = self::$handles[$event] ?? null;
    if (!is_null($handlers)) {
      foreach ($handlers as $handler) {
        invoke($handler, $args);
      }
    }
  }

  /**
   * 服务关闭前事件
   *
   * @param Server $server
   * @return void
   */
  private static function onBeforeShutdown(Server $server): void
  {
    // 触发服务关闭前事件，允许用户在服务关闭前执行一些清理工作
    Event::emit('ServerShutdownBefore', [$server]);
  }

  /**
   * 监听服务关闭事件
   *
   * @param Server $server
   * @return void
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
   * 监听服务启动代理信号
   *
   * @param Server $server 服务实例
   * @return void
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
    Event::emit('AfterStartServer', [$server]);
  }
}
